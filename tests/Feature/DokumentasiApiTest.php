<?php

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
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/guru/{id}']['put'], 403))
        ->toBe(['FORBIDDEN', 'ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/guru/{id}']['put'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/auth/login']['post'], 403))
        ->toBe(['ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/wali/tautkan-anak']['post'], 429))->toBe(['TOO_MANY_REQUESTS'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/auth/google']['post'], 503))->toBe(['SERVER_ERROR'])
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
        ->and($skema['MuridResource']['properties']['wali']['items']['properties'])->toHaveKey('hubungan')
        ->and($skema['RaporResource']['properties']['detail']['items']['properties']['foto_url']['type'])->toBe(['string', 'null'])
        ->and($skema['MuridResource']['properties']['foto_url']['type'])->toBe(['string', 'null'])
        ->and($skema['UserResource']['properties']['avatar_url']['type'])->toBe(['string', 'null'])
        ->and($skema['SimpanPengaturanRequest']['properties']['items']['type'])->toBe('object');
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
        ->and($dashboard['anyOf'][2]['required'])->toContain('anak', 'tagihan_aktif', 'rapor_terbaru')
        ->and($dokumen['paths']['/public/ppdb']['get']['responses'][200]['content']['application/json']['schema']['properties']['data']['properties']['dibuka'])->toBe(['type' => 'boolean']);
});
