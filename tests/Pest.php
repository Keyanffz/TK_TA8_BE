<?php

use App\Enums\StatusAkun;
use App\Enums\Tingkat;
use App\Models\Guru;
use App\Models\Pendaftaran;
use App\Models\Pengaturan;
use App\Models\User;
use App\Notifications\PendaftaranBaruNotification;
use App\Services\GoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Akun Kepala Sekolah beserta profil gurunya, seperti hasil SuperAdminSeeder.
 */
function buatKepalaSekolah(): User
{
    $user = User::factory()->superAdmin()->create();
    Guru::factory()->for($user)->kelolaKeuangan()->create(['jabatan' => Guru::JABATAN_KEPALA_SEKOLAH]);

    return $user;
}

function buatGuru(StatusAkun $status = StatusAkun::Aktif, array $atributGuru = []): Guru
{
    return Guru::factory()->for(User::factory()->status($status))->create($atributGuru);
}

/** Titik sekolah di pengaturan absensi untuk test; 0,0004 derajat lintang kira-kira 44 m. */
const LATITUDE_SEKOLAH = -6.9903;

const LONGITUDE_SEKOLAH = 110.4229;

/**
 * Mengubah kunci grup pengaturan `absensi`, misalnya `aturAbsensi(['radius_meter' => 50])`. Nilai bawaannya
 * sudah diisi migration.
 *
 * @param  array<string, mixed>  $nilai
 */
function aturAbsensi(array $nilai): void
{
    foreach ($nilai as $nama => $isi) {
        Pengaturan::query()->updateOrCreate(['kunci' => "absensi.{$nama}"], ['nilai' => $isi, 'grup' => 'absensi']);
    }
}

/**
 * Isian `POST /absensi` dari dalam area sekolah dengan akurasi baik.
 *
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function isianAbsen(string $jenis = 'masuk', array $ubah = []): array
{
    return [
        'jenis' => $jenis,
        'latitude' => LATITUDE_SEKOLAH + 0.0004,
        'longitude' => LONGITUDE_SEKOLAH,
        'akurasi' => 15,
        'foto' => UploadedFile::fake()->image('swafoto.jpg', 640, 480),
        ...$ubah,
    ];
}

const CLIENT_ID_GOOGLE = '1234567890-tkta8.apps.googleusercontent.com';

const SUB_GOOGLE = '109876543210987654321';

/**
 * Notifikasi database untuk test yang hanya butuh notifikasi tersimpan (daftar, filter, tandai dibaca).
 */
function notifikasiPendaftarBaru(int $pendaftaranId, string $namaAnak): PendaftaranBaruNotification
{
    return new PendaftaranBaruNotification(Pendaftaran::factory()->make([
        'id' => $pendaftaranId,
        'kode' => sprintf('PPDB-2027-%04d', $pendaftaranId),
        'nama_lengkap' => $namaAnak,
        'tingkat_tujuan' => Tingkat::A,
        'wali_murid_id' => null,
        'tahun_ajaran_id' => null,
    ]));
}

/**
 * Pasangan kunci RSA pengganti kunci Google. Kunci publiknya dilayani lewat Http::fake di alamat kunci publik
 * Google (palsukanGoogle), jadi verifikasi tanda tangan, `aud`, `iss`, dan `exp` di GoogleIdTokenVerifier
 * benar-benar berjalan tanpa menghubungi Google.
 *
 * @return array{privat: OpenSSLAsymmetricKey, jwks: array<string, mixed>}
 */
function kunciGooglePalsu(string $kid = 'kunci-uji-1'): array
{
    static $kunci = [];

    if (! isset($kunci[$kid])) {
        $privat = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($privat)['rsa'];
        $kunci[$kid] = [
            'privat' => $privat,
            'jwks' => ['keys' => [[
                'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid,
                'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e']),
            ]]],
        ];
    }

    return $kunci[$kid];
}

/**
 * ID token seperti yang dikirim Google Identity Services ke FE.
 */
function idTokenGoogle(array $klaim = [], ?OpenSSLAsymmetricKey $kunciPenanda = null): string
{
    return JWT::encode([
        'iss' => 'https://accounts.google.com',
        'aud' => CLIENT_ID_GOOGLE,
        'sub' => SUB_GOOGLE,
        'email' => 'nur.aini@gmail.com',
        'email_verified' => true,
        'name' => 'Nur Aini',
        'iat' => now()->timestamp,
        'exp' => now()->addHour()->timestamp,
        ...$klaim,
    ], $kunciPenanda ?? kunciGooglePalsu()['privat'], 'RS256', 'kunci-uji-1');
}

/**
 * Mengarahkan unduhan kunci publik Google ke kunci uji. Isi `$this->googleGangguan = true` di test untuk meniru
 * server Google yang gagal.
 */
function palsukanGoogle(TestCase $test): void
{
    config(['services.google.client_id' => CLIENT_ID_GOOGLE]);
    Http::preventStrayRequests();
    Http::fake([GoogleIdTokenVerifier::URL_KUNCI_PUBLIK => fn () => ($test->googleGangguan ?? false)
        ? Http::response('gangguan', 500)
        : Http::response(kunciGooglePalsu()['jwks'], 200, ['Cache-Control' => 'public, max-age=21600'])]);
}

/**
 * Mencocokkan nilai JSON dengan skema OpenAPI: tipe, field wajib, dan field yang tidak terdokumentasi.
 * Cukup untuk bentuk yang dipakai api.json ini ($ref ke components, type tunggal atau daftar, anyOf, allOf).
 *
 * @return list<string> daftar ketidaksesuaian
 */
function selisihDenganSkema(mixed $nilai, array $skema, array $dokumen, string $jalur = 'data'): array
{
    if (isset($skema['$ref'])) {
        $skema = $dokumen['components']['schemas'][str_replace('#/components/schemas/', '', $skema['$ref'])];
    }
    if (isset($skema['anyOf'])) {
        foreach ($skema['anyOf'] as $pilihan) {
            if (selisihDenganSkema($nilai, $pilihan, $dokumen, $jalur) === []) {
                return [];
            }
        }

        return ["{$jalur}: tidak cocok dengan pilihan anyOf mana pun"];
    }
    if (isset($skema['allOf'])) {
        $gabungan = ['properties' => [], 'required' => []];
        foreach ($skema['allOf'] as $bagian) {
            $bagian = isset($bagian['$ref']) ? $dokumen['components']['schemas'][str_replace('#/components/schemas/', '', $bagian['$ref'])] : $bagian;
            $gabungan = [...$bagian, ...$gabungan, 'properties' => [...$gabungan['properties'], ...$bagian['properties'] ?? []], 'required' => [...$gabungan['required'], ...$bagian['required'] ?? []]];
        }
        $skema = $gabungan;
    }

    $tipe = match (true) {
        is_null($nilai) => 'null',
        is_bool($nilai) => 'boolean',
        is_int($nilai) => 'integer',
        is_float($nilai) => 'number',
        is_string($nilai) => 'string',
        is_array($nilai) && array_is_list($nilai) && ($nilai !== [] || ($skema['type'] ?? null) !== 'object') => 'array',
        default => 'object',
    };
    $boleh = (array) ($skema['type'] ?? (isset($skema['enum']) ? 'string' : $tipe));
    if (! in_array($tipe, $boleh, true) && ! ($tipe === 'integer' && in_array('number', $boleh, true))) {
        return ["{$jalur}: bertipe {$tipe}, dokumentasi ".implode('|', $boleh)];
    }

    $selisih = [];
    if ($tipe === 'object') {
        foreach ($skema['required'] ?? [] as $wajib) {
            if (! array_key_exists($wajib, $nilai)) {
                $selisih[] = "{$jalur}.{$wajib}: wajib menurut dokumentasi tetapi tidak ada";
            }
        }
        foreach ($nilai as $kunci => $isi) {
            if (isset($skema['properties'][$kunci])) {
                $selisih = [...$selisih, ...selisihDenganSkema($isi, $skema['properties'][$kunci], $dokumen, "{$jalur}.{$kunci}")];
            } elseif (! isset($skema['additionalProperties'])) {
                $selisih[] = "{$jalur}.{$kunci}: ada di respons tetapi tidak terdokumentasi";
            }
        }
    }
    if ($tipe === 'array' && isset($skema['items'])) {
        foreach ($nilai as $i => $isi) {
            $selisih = [...$selisih, ...selisihDenganSkema($isi, $skema['items'], $dokumen, "{$jalur}[{$i}]")];
        }
    }

    return $selisih;
}

/**
 * Mencatat, per lokasi skema, field mana saja yang muncul di setiap objek respons dan field mana yang wajib
 * menurut dokumentasi. Skema komponen (`$ref`) dicatat per nama komponen, karena satu skema dipakai bersama
 * oleh beberapa endpoint dan role: field hanya boleh wajib kalau selalu ada di semuanya. Objek inline dicatat
 * per jalur tanpa indeks array; pilihan anyOf ikut jadi bagian jalur karena tiap pilihan punya field wajib
 * sendiri.
 *
 * @param  array<string, array{wajib: list<string>, selalu: list<string>}>  $catatan
 */
function catatFieldSelaluAda(mixed $nilai, array $skema, array $dokumen, string $lokasi, array &$catatan): void
{
    if (isset($skema['$ref'])) {
        $lokasi = str_replace('#/components/schemas/', '', $skema['$ref']);
        $skema = $dokumen['components']['schemas'][$lokasi];
    }
    if (isset($skema['anyOf'])) {
        foreach ($skema['anyOf'] as $i => $pilihan) {
            if (selisihDenganSkema($nilai, $pilihan, $dokumen) === []) {
                catatFieldSelaluAda($nilai, $pilihan, $dokumen, "{$lokasi}(anyOf {$i})", $catatan);

                return;
            }
        }

        return;
    }
    if (! is_array($nilai)) {
        return;
    }

    if (array_is_list($nilai) && ($nilai !== [] || ($skema['type'] ?? null) !== 'object')) {
        foreach ($nilai as $isi) {
            catatFieldSelaluAda($isi, $skema['items'] ?? [], $dokumen, "{$lokasi}[]", $catatan);
        }

        return;
    }

    // Objek berkunci bebas (misalnya pengaturan per grup) tidak punya daftar properti yang bisa diwajibkan.
    if (! isset($skema['properties'])) {
        return;
    }

    $kunci = array_keys($nilai);
    $catatan[$lokasi] = [
        'wajib' => $skema['required'] ?? [],
        'selalu' => isset($catatan[$lokasi]) ? array_values(array_intersect($catatan[$lokasi]['selalu'], $kunci)) : $kunci,
    ];
    foreach ($nilai as $nama => $isi) {
        if (isset($skema['properties'][$nama])) {
            catatFieldSelaluAda($isi, $skema['properties'][$nama], $dokumen, "{$lokasi}.{$nama}", $catatan);
        }
    }
}

function skemaSukses(array $dokumen, string $path): array
{
    return $dokumen['paths'][$path]['get']['responses'][200]['content']['application/json']['schema'] ?? [];
}
