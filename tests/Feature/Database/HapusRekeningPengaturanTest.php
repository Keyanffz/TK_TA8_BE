<?php

use App\Models\Pengaturan;

it('menghapus rekening sekolah dari pengaturan tanpa menyentuh kunci keuangan lain', function () {
    $kepsek = buatKepalaSekolah();
    Pengaturan::query()->create([
        'kunci' => 'keuangan.rekening',
        'nilai' => [['bank' => 'Bank Jateng', 'nomor' => '2012345678', 'atas_nama' => 'TK Tarbiyatul Athfal 8']],
        'grup' => 'keuangan',
    ]);
    Pengaturan::query()->updateOrCreate(['kunci' => 'keuangan.tanggal_jatuh_tempo'], ['nilai' => 10, 'grup' => 'keuangan']);
    $this->actingAs($kepsek)->getJson('/api/v1/pengaturan?grup=keuangan')->assertOk();

    (require database_path('migrations/2026_11_14_100000_hapus_rekening_dari_pengaturan.php'))->up();

    expect(Pengaturan::query()->where('kunci', 'keuangan.rekening')->exists())->toBeFalse();
    $pengaturan = $this->actingAs($kepsek)->getJson('/api/v1/pengaturan?grup=keuangan')->assertOk()->json('data');
    expect($pengaturan)->not->toHaveKey('keuangan.rekening')
        ->and($pengaturan['keuangan.tanggal_jatuh_tempo'])->toBe(10);
});
