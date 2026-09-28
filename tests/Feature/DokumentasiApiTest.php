<?php

use App\Enums\Hubungan;
use App\Models\Agenda;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pengumuman;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Support\Facades\Storage;

it('membuka /docs/api di environment selain production', function () {
    $this->get('/docs/api')->assertOk();
});

it('menutup /docs/api di production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/docs/api')->assertForbidden();
});

/**
 * @return list<string>
 */
function kodeErrorTerdokumentasi(array $operasi, int $status): array
{
    return $operasi['responses'][$status]['content']['application/json']['schema']['properties']['code']['enum'] ?? [];
}

it('mendokumentasikan auth Bearer dan format error A7', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    $me = $dokumen['paths']['/auth/me']['get'];

    expect($dokumen['servers'][0]['url'])->toEndWith('/api/v1')
        ->and($dokumen['components']['securitySchemes'])->toContain(['type' => 'http', 'scheme' => 'bearer'])
        ->and($dokumen['paths']['/health']['get']['security'])->toBe([])
        ->and($dokumen['paths']['/health']['get']['responses'])->toHaveKeys([200])
        ->and($me['responses'][401]['content']['application/json']['schema']['required'])->toBe(['success', 'message', 'code', 'errors'])
        ->and(kodeErrorTerdokumentasi($me, 401))->toBe(['UNAUTHENTICATED']);
});

it('mendokumentasikan respons 403 dari middleware role dan status akun', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();

    expect(kodeErrorTerdokumentasi($dokumen['paths']['/auth/me']['get'], 403))
        ->toBe(['ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/auth/password']['put'], 403))
        ->toBe(['ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/guru/{id}']['put'], 403))
        ->toBe(['FORBIDDEN', 'ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE', 'PASSWORD_WAJIB_DIGANTI'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/guru/{id}']['put'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/auth/login']['post'], 403))
        ->toBe(['ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/auth/login-wali']['post'], 403))
        ->toBe(['ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/auth/login-wali']['post'], 429))->toBe(['TOO_MANY_REQUESTS'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/wali/tambah-anak']['post'], 429))->toBe(['TOO_MANY_REQUESTS'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/media/{token}']['get'], 403))->toBe(['FORBIDDEN']);
});

it('mendokumentasikan 404 untuk data di luar jangkauan pengguna', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();

    expect(kodeErrorTerdokumentasi($dokumen['paths']['/murid/{id}']['get'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/kelas/{id}']['get'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/tagihan/{id}']['get'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/murid']['get'], 403))->not->toContain('FORBIDDEN');

    foreach (['/murid/{id}', '/tagihan/{id}', '/kegiatan/{id}', '/rapor/{id}', '/rapor/{id}/pdf', '/pengumuman/{id}'] as $path) {
        expect(kodeErrorTerdokumentasi($dokumen['paths'][$path]['get'], 403))->not->toContain('FORBIDDEN');
    }
});

it('mendokumentasikan respons file hanya dengan tipe file, dan 403 untuk guru tanpa izin keuangan', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    $tipeFile = [
        '/pembayaran/{id}/bukti' => 'image/jpeg',
        '/pembayaran/{id}/kwitansi' => 'application/pdf',
        '/rapor/{id}/pdf' => 'application/pdf',
        '/laporan/keuangan/export' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        '/media/{token}' => 'application/octet-stream',
    ];

    foreach ($tipeFile as $path => $tipe) {
        expect(array_keys($dokumen['paths'][$path]['get']['responses'][200]['content']))->toBe([$tipe]);
    }
    foreach (['/pembayaran/{id}', '/pembayaran/{id}/bukti', '/pembayaran/{id}/kwitansi'] as $path) {
        expect(kodeErrorTerdokumentasi($dokumen['paths'][$path]['get'], 403))->toContain('FORBIDDEN');
    }
});

it('mendokumentasikan tipe item array bertingkat dan url file yang bisa null', function () {
    $skema = $this->getJson('/docs/api.json')->assertOk()->json('components.schemas');

    expect($skema['KegiatanKelasResource']['properties']['foto']['items']['properties'])->toHaveKeys(['id', 'url', 'caption', 'urutan'])
        ->and($skema['MuridDetailResource']['properties']['wali']['items']['properties'])->toHaveKey('hubungan')
        ->and($skema['RaporDetailResource']['properties']['detail']['items']['properties']['foto_url']['type'])->toBe(['string', 'null'])
        ->and($skema['MuridResource']['properties']['foto_url']['type'])->toBe(['string', 'null'])
        ->and($skema['UserResource']['properties']['avatar_url']['type'])->toBe(['string', 'null'])
        ->and($skema['SimpanPengaturanRequest']['properties']['items']['type'])->toBe('object');
});

/**
 * Semua skema `allOf` di dokumen beserta jalurnya.
 *
 * @return array<string, array<mixed>>
 */
function skemaAllOf(mixed $node, string $jalur = ''): array
{
    if (! is_array($node)) {
        return [];
    }

    $hasil = isset($node['allOf']) ? [$jalur => $node['allOf']] : [];
    foreach ($node as $kunci => $anak) {
        $hasil = [...$hasil, ...skemaAllOf($anak, "{$jalur}/{$kunci}")];
    }

    return $hasil;
}

it('mereferensikan skema Resource langsung tanpa allOf berisi objek kosong', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    $data = fn (string $path) => $dokumen['paths'][$path]['get']['responses'][200]['content']['application/json']['schema']['properties']['data'];
    $wali = $data('/dashboard')['anyOf'][2]['properties'];

    // openapi-typescript menerjemahkan objek tanpa properties di allOf menjadi `Record<string, never>`.
    $objekKosong = array_filter(skemaAllOf($dokumen), fn (array $bagian) => collect($bagian)
        ->contains(fn (array $satu) => ($satu['type'] ?? null) === 'object' && empty($satu['properties'])));

    expect($objekKosong)->toBe([])
        ->and($data('/tagihan')['items'])->toBe(['$ref' => '#/components/schemas/TagihanResource'])
        ->and($data('/kegiatan')['items'])->toBe(['$ref' => '#/components/schemas/KegiatanKelasResource'])
        ->and($wali['tagihan_aktif']['items'])->toBe(['$ref' => '#/components/schemas/TagihanResource'])
        ->and($wali['kegiatan_terbaru']['items'])->toBe(['$ref' => '#/components/schemas/KegiatanKelasResource'])
        ->and($wali['pengumuman_terbaru']['items'])->toBe(['$ref' => '#/components/schemas/PengumumanResource'])
        ->and($data('/murid/{id}'))->toBe(['$ref' => '#/components/schemas/MuridDetailResource'])
        ->and($data('/tagihan/{id}'))->toBe(['$ref' => '#/components/schemas/TagihanDetailResource']);
});

it('mewajibkan field relasi yang selalu dikirim dan membiarkan opsional hanya field yang bergantung role', function () {
    $skema = $this->getJson('/docs/api.json')->assertOk()->json('components.schemas');
    $opsional = fn (string $nama) => array_values(array_diff(array_keys($skema[$nama]['properties']), $skema[$nama]['required']));

    expect($skema['TagihanResource']['required'])->toContain('murid', 'jenis_tagihan')
        ->and($skema['TagihanDetailResource']['required'])->toContain('murid', 'jenis_tagihan', 'pembayaran', 'rekening')
        ->and($skema['PembayaranResource']['required'])->toContain('tagihan', 'dibayar_oleh', 'diverifikasi_oleh')
        ->and($skema['KelasDetailResource']['required'])->toContain('jumlah_murid', 'tahun_ajaran', 'murid')
        ->and($skema['WaliMuridDetailResource']['required'])->toContain('user', 'jumlah_anak', 'anak')
        ->and($skema['RaporResource']['required'])->toContain('murid', 'kelas', 'tahun_ajaran', 'pembuat')
        ->and($skema['RaporDetailResource']['required'])->toContain('detail')
        ->and($opsional('MuridDetailResource'))->toBe([])
        ->and($opsional('RaporResource'))->toBe(['catatan_revisi'])
        ->and($opsional('PengumumanResource'))->toBe(['kelas', 'murid'])
        ->and($opsional('GuruResource'))->toBe(['password_awal']);
});

it('mendokumentasikan jenis notifikasi sebagai enum dan rapor PDF sebagai file', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();

    expect($dokumen['components']['schemas']['NotifikasiResource']['properties']['jenis'])->toBe(['$ref' => '#/components/schemas/JenisNotifikasi'])
        ->and($dokumen['components']['schemas']['JenisNotifikasi']['enum'])->toContain('tagihan_tertunda', 'rapor_terbit', 'pengumuman_baru')
        ->and($dokumen['paths']['/rapor/{id}/pdf']['get']['responses'][200]['content'])->toHaveKey('application/pdf')
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/rapor/{id}']['get'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/notifikasi/{id}/baca']['post'], 404))->toBe(['NOT_FOUND']);
});

it('mendokumentasikan endpoint publik tanpa auth dan dashboard sebagai gabungan tiga bentuk role', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    $dashboard = $dokumen['paths']['/dashboard']['get']['responses'][200]['content']['application/json']['schema']['properties']['data'];

    foreach (['/public/profil', '/public/pengumuman', '/public/agenda', '/public/galeri', '/public/guru', '/public/ppdb'] as $path) {
        expect($dokumen['paths'][$path]['get']['security'])->toBe([]);
    }

    expect($dashboard['anyOf'])->toHaveCount(3)
        ->and($dashboard['anyOf'][0]['required'])->toContain('statistik', 'grafik_pemasukan', 'tertunda')
        ->and($dashboard['anyOf'][1]['required'])->toContain('kelas_saya', 'progres_rapor', 'pembayaran_menunggu')
        ->and($dashboard['anyOf'][2]['required'])->toContain('anak', 'tagihan_aktif', 'rapor_terbaru', 'info_sekolah')
        ->and($dashboard['anyOf'][2]['properties']['info_sekolah']['type'])->toBe(['object', 'null'])
        ->and($dashboard['anyOf'][2]['properties']['info_sekolah']['properties']['nada'])->toBe(['$ref' => '#/components/schemas/NadaInfo'])
        ->and($dokumen['paths']['/public/ppdb']['get']['responses'][200]['content']['application/json']['schema']['properties']['data']['properties']['dibuka'])->toBe(['type' => 'boolean']);
});

it('mendokumentasikan meta paginasi sebagai angka di semua endpoint berpaginasi', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    $berpaginasi = [];

    foreach ($dokumen['paths'] as $path => $operasi) {
        $meta = isset($operasi['get']) ? (skemaSukses($dokumen, $path)['properties']['meta'] ?? null) : null;

        if (($meta['type'] ?? null) === 'object') {
            $berpaginasi[$path] = array_map(fn (array $properti) => $properti['type'], $meta['properties']);
        }
    }

    expect($berpaginasi)->toHaveCount(18);
    foreach ($berpaginasi as $path => $tipe) {
        expect($tipe)->toBe(['current_page' => 'integer', 'per_page' => 'integer', 'total' => 'integer', 'last_page' => 'integer'], $path);
    }
});

it('mencocokkan respons daftar berpaginasi dengan dokumentasinya, termasuk meta', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    Agenda::factory()->create();
    Pengumuman::factory()->count(2)->create(['is_publik' => true]);

    $respons = $this->getJson('/api/v1/public/pengumuman?per_page=1')->assertOk()->json();

    expect(selisihDenganSkema($respons, skemaSukses($dokumen, '/public/pengumuman'), $dokumen, 'respons'))->toBe([])
        ->and($respons['meta'])->toBe(['current_page' => 1, 'per_page' => 1, 'total' => 2, 'last_page' => 2]);
});

it('mendokumentasikan GET /public/profil sesuai kunci pengaturan dan respons sebenarnya', function () {
    Storage::fake('public');
    $this->seed(PengaturanSeeder::class);
    $kepsek = buatKepalaSekolah();
    $this->actingAs($kepsek)->putJson('/api/v1/pengaturan', ['items' => [
        'landing.fasilitas' => [['nama' => 'Taman bermain']],
        'landing.keunggulan' => [['judul' => 'Guru berpengalaman', 'deskripsi' => 'Rata-rata mengajar lebih dari sepuluh tahun.', 'ikon' => 'award']],
    ]])->assertOk();
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    $skema = skemaSukses($dokumen, '/public/profil')['properties']['data'];

    expect(array_keys($skema['properties']))->toBe([
        'profil.nama_sekolah', 'profil.npsn', 'profil.alamat', 'profil.telepon', 'profil.email', 'profil.maps_embed_url',
        'profil.logo', 'profil.logo_url', 'profil.visi', 'profil.misi', 'profil.sejarah', 'profil.sambutan_kepsek',
        'landing.hero', 'landing.program', 'landing.fasilitas', 'landing.keunggulan',
    ])
        ->and($skema)->not->toHaveKey('additionalProperties')
        ->and($skema['properties']['profil.misi'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($skema['properties']['landing.hero']['properties']['gambar_url']['type'])->toBe(['string', 'null']);

    $respons = $this->getJson('/api/v1/public/profil')->assertOk()->json('data');
    expect(array_keys($respons))->toEqualCanonicalizing(array_keys($skema['properties']))
        ->and(selisihDenganSkema($respons, $skema, $dokumen))->toBe([]);
});

it('mendokumentasikan id kelas di data anak, murid, dan tagihan sebagai angka sesuai respons', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    $skema = $dokumen['components']['schemas'];

    expect($skema['AnakWaliResource']['properties']['kelas']['properties']['id']['type'])->toBe('integer')
        ->and($skema['MuridResource']['properties']['kelas']['properties']['id']['type'])->toBe('integer')
        ->and($skema['TagihanResource']['properties']['murid']['properties']['kelas']['properties']['id']['type'])->toBe('integer');

    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create();
    $murid = Murid::factory()->create();
    $kelas->murid()->attach($murid);
    $wali = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $anak = $this->actingAs($wali->user)->getJson('/api/v1/wali/anak')->assertOk()->json('data');
    $detailMurid = $this->actingAs($wali->user)->getJson("/api/v1/murid/{$murid->id}")->assertOk()->json('data');

    expect($anak[0]['kelas']['id'])->toBe($kelas->id)
        ->and(selisihDenganSkema($anak, skemaSukses($dokumen, '/wali/anak')['properties']['data'], $dokumen))->toBe([])
        ->and(selisihDenganSkema($detailMurid, $skema['MuridDetailResource'], $dokumen))->toBe([]);
});

/**
 * Skema body request sebuah operasi, `$ref` komponen sudah diurai.
 *
 * @return array{tipe: string, skema: array<mixed>}
 */
function skemaBodyRequest(array $dokumen, string $path, string $metode): array
{
    $konten = $dokumen['paths'][$path][$metode]['requestBody']['content'];
    $tipe = array_key_first($konten);
    $skema = $konten[$tipe]['schema'];

    if (isset($skema['$ref'])) {
        $skema = $dokumen['components']['schemas'][str_replace('#/components/schemas/', '', $skema['$ref'])];
    }

    return ['tipe' => $tipe, 'skema' => $skema];
}

it('mendokumentasikan PUT /kegiatan/{id} tanpa kelas_id dan foto, sesuai validasinya', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();

    $perbarui = skemaBodyRequest($dokumen, '/kegiatan/{id}', 'put');
    $simpan = skemaBodyRequest($dokumen, '/kegiatan', 'post');

    expect($perbarui['tipe'])->toBe('application/json')
        ->and(array_keys($perbarui['skema']['properties']))->toEqualCanonicalizing(['tanggal', 'tema', 'judul', 'deskripsi'])
        ->and($perbarui['skema']['required'])->toEqualCanonicalizing(['tanggal', 'judul'])
        ->and($simpan['tipe'])->toBe('multipart/form-data')
        ->and($simpan['skema']['required'])->toContain('kelas_id')
        ->and($simpan['skema']['properties'])->toHaveKey('foto');
});
