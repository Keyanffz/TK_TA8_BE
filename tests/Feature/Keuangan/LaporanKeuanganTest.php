<?php

use App\Enums\Hubungan;
use App\Enums\MetodeBayar;
use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Exports\LaporanKeuanganExport;
use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Maatwebsite\Excel\Facades\Excel;

/**
 * SPP Rp 150.000 Agustus–September untuk Aisyah (TK A1) dan Bima (TK B1), Seragam Rp 350.000 untuk Aisyah.
 * Lunas: SPP Agustus keduanya (dibayar Agustus) dan Seragam (dibayar September). SPP September Aisyah
 * terlambat; SPP September Bima dibatalkan.
 */
beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->kelasA1 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1']);
    $kelasB1 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK B1']);
    $this->aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri', 'nis' => 'TA20260001']);
    $this->bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $this->kelasA1->murid()->attach($this->aisyah);
    $kelasB1->murid()->attach($this->bima);
    $ibu = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $this->ibu = $ibu;

    $spp = JenisTagihan::factory()->for($tahunAjaran)->create(['nama' => 'SPP']);
    $seragam = JenisTagihan::factory()->for($tahunAjaran)->sekali()->create(['nama' => 'Seragam', 'nominal' => 350000]);

    $tagihan = fn (Murid $murid, JenisTagihan $jenis, string $jatuhTempo, StatusTagihan $status, int $total = 150000) => Tagihan::factory()
        ->for($murid)->for($jenis)->create(['jatuh_tempo' => $jatuhTempo, 'status' => $status, 'nominal' => $total, 'total' => $total, 'periode' => $jenis->is($spp) ? substr($jatuhTempo, 0, 8).'01' : null]);
    $bayar = fn (Tagihan $t, string $tanggal) => Pembayaran::factory()->for($t)->create(['status' => StatusPembayaran::Diterima, 'tanggal_bayar' => $tanggal, 'metode' => MetodeBayar::Tunai]);

    $bayar($tagihan($this->aisyah, $spp, '2026-08-10', StatusTagihan::Lunas), '2026-08-05');
    $bayar($tagihan($this->bima, $spp, '2026-08-10', StatusTagihan::Lunas), '2026-08-09');
    $bayar($tagihan($this->aisyah, $seragam, '2026-08-31', StatusTagihan::Lunas, 350000), '2026-09-02');
    $this->terlambat = $tagihan($this->aisyah, $spp, '2026-09-10', StatusTagihan::Terlambat);
    $tagihan($this->bima, $spp, '2026-09-10', StatusTagihan::Dibatalkan);
});

it('meringkas tagihan, pembayaran, dan pemasukan per jenis dan per bulan', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/laporan/keuangan?dari=2026-08-01&sampai=2026-09-30')
        ->assertOk()
        ->assertJsonPath('data.ringkasan', [
            'jumlah_tagihan' => 4,
            'total_tagihan' => 800000,
            'terbayar' => 650000,
            'belum_terbayar' => 150000,
            'persen_lunas' => 81.3,
            'pemasukan' => 650000,
        ])
        ->assertJsonPath('data.per_jenis.0.jenis_tagihan.nama', 'Seragam')
        ->assertJsonPath('data.per_jenis.0.total_tagihan', 350000)
        ->assertJsonPath('data.per_jenis.1.jenis_tagihan.nama', 'SPP')
        ->assertJsonPath('data.per_jenis.1.belum_terbayar', 150000)
        ->assertJsonPath('data.per_bulan.0', [
            'bulan' => '2026-08', 'jumlah_tagihan' => 3, 'total_tagihan' => 650000, 'terbayar' => 650000,
            'belum_terbayar' => 0, 'persen_lunas' => 100, 'pemasukan' => 300000,
        ])
        ->assertJsonPath('data.per_bulan.1.bulan', '2026-09')
        ->assertJsonPath('data.per_bulan.1.pemasukan', 350000);
});

it('membatasi laporan keuangan ke satu kelas', function () {
    $this->actingAs($this->kepsek)->getJson("/api/v1/laporan/keuangan?dari=2026-08-01&sampai=2026-09-30&kelas_id={$this->kelasA1->id}")
        ->assertOk()
        ->assertJsonPath('data.ringkasan.jumlah_tagihan', 3)
        ->assertJsonPath('data.ringkasan.pemasukan', 500000);
});

it('memvalidasi rentang laporan keuangan', function (string $query, string $field) {
    $this->actingAs($this->kepsek)->getJson("/api/v1/laporan/keuangan?{$query}")
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'tanpa tanggal' => ['', 'dari'],
    'sampai sebelum dari' => ['dari=2026-09-01&sampai=2026-08-01', 'sampai'],
    'lebih dari dua tahun' => ['dari=2024-01-01&sampai=2026-09-30', 'sampai'],
    'format tanggal salah' => ['dari=01-08-2026&sampai=2026-09-30', 'dari'],
]);

it('menampilkan daftar murid menunggak beserta kontak utama walinya', function () {
    $this->actingAs(Guru::factory()->kelolaKeuangan()->create()->user)->getJson('/api/v1/laporan/tunggakan')
        ->assertOk()
        ->assertJsonPath('data.total_tunggakan', 150000)
        ->assertJsonPath('data.jumlah_murid', 1)
        ->assertJsonPath('data.murid.0.nama_lengkap', 'Aisyah Putri')
        ->assertJsonPath('data.murid.0.kelas.nama', 'TK A1')
        ->assertJsonPath('data.murid.0.kontak_wali.nama', $this->ibu->user->name)
        ->assertJsonPath('data.murid.0.tagihan.0.id', $this->terlambat->id)
        ->assertJsonPath('data.murid.0.tagihan.0.nama', 'SPP September 2026');

    $this->getJson('/api/v1/laporan/tunggakan?kelas_id='.Kelas::query()->where('nama', 'TK B1')->value('id'))
        ->assertJsonPath('data.jumlah_murid', 0);
});

it('mengunduh laporan keuangan sebagai file Excel', function () {
    Excel::fake();

    $this->actingAs($this->kepsek)->get('/api/v1/laporan/keuangan/export?dari=2026-08-01&sampai=2026-09-30')->assertOk();

    Excel::assertDownloaded('laporan-keuangan-2026-08-01-sd-2026-09-30.xlsx', function (LaporanKeuanganExport $export): bool {
        [$tagihan, $pembayaran] = $export->sheets();

        return $tagihan->query()->count() === 4
            && $tagihan->map($tagihan->query()->first())[4] === 'SPP Agustus 2026'
            && $pembayaran->query()->count() === 3;
    });
});

it('menghasilkan file xlsx yang benar-benar bisa dibuka', function () {
    $response = $this->actingAs($this->kepsek)->get('/api/v1/laporan/keuangan/export?dari=2026-08-01&sampai=2026-09-30')
        ->assertOk()
        ->assertDownload('laporan-keuangan-2026-08-01-sd-2026-09-30.xlsx');

    expect(substr((string) file_get_contents($response->baseResponse->getFile()->getPathname()), 0, 2))->toBe('PK');
});

it('menutup laporan untuk guru tanpa izin keuangan', function () {
    $guru = buatGuru()->user;

    $this->actingAs($guru)->getJson('/api/v1/laporan/keuangan?dari=2026-08-01&sampai=2026-09-30')->assertForbidden();
    $this->actingAs($guru)->get('/api/v1/laporan/keuangan/export?dari=2026-08-01&sampai=2026-09-30')->assertForbidden();
    $this->actingAs($guru)->getJson('/api/v1/laporan/tunggakan')->assertForbidden();
});
