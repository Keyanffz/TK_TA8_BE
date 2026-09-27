<?php

use App\Enums\StatusAkun;
use App\Models\Guru;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
