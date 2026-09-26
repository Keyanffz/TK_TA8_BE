<?php

use App\Enums\Hubungan;
use App\Enums\MetodeBayar;
use App\Enums\StatusTagihan;
use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Pengaturan;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;

/**
 * Aisyah di TK A1 (wali kelas Bu Aini), Bima di TK B1. Ibu Aisyah hanya tertaut ke Aisyah.
 * Bu Siti bukan pengampu kelas mana pun tetapi punya izin kelola keuangan.
 */
beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $aktif = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->spp = JenisTagihan::factory()->for($aktif)->create(['nama' => 'SPP']);

    $this->buAini = buatGuru();
    $this->buSiti = Guru::factory()->kelolaKeuangan()->create();
    $this->kelasA1 = Kelas::factory()->for($aktif)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
    $kelasB1 = Kelas::factory()->for($aktif)->create(['nama' => 'TK B1']);

    $this->aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri']);
    $this->bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $this->kelasA1->murid()->attach($this->aisyah);
    $kelasB1->murid()->attach($this->bima);

    $this->ibuAisyah = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibuAisyah, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->tagihanAisyah = Tagihan::factory()->for($this->aisyah)->for($this->spp)->create(['kode' => 'INV-202609-00001']);
    $this->tagihanBima = Tagihan::factory()->for($this->bima)->for($this->spp)->create(['kode' => 'INV-202609-00002', 'status' => StatusTagihan::Terlambat]);
});

it('menampilkan semua tagihan ke petugas keuangan', function (Closure $petugas) {
    $this->actingAs($petugas->call($this))->getJson('/api/v1/tagihan')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
})->with([
    'Kepala Sekolah' => [fn () => $this->kepsek],
    'guru dengan izin keuangan' => [fn () => $this->buSiti->user],
]);

it('menampilkan ke guru hanya tagihan murid kelasnya', function () {
    $this->actingAs($this->buAini->user)->getJson('/api/v1/tagihan')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->tagihanAisyah->id)
        ->assertJsonPath('data.0.murid.kelas', ['id' => $this->kelasA1->id, 'nama' => 'TK A1'])
        ->assertJsonPath('data.0.jenis_tagihan.nama', 'SPP');
});

it('menampilkan ke wali murid hanya tagihan anaknya', function () {
    $this->actingAs($this->ibuAisyah->user)->getJson('/api/v1/tagihan')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kode', 'INV-202609-00001');
});

it('membalas 404 saat guru membuka tagihan murid kelas lain', function () {
    $this->actingAs($this->buAini->user)->getJson("/api/v1/tagihan/{$this->tagihanBima->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('membalas 404 saat wali murid membuka tagihan anak orang lain', function () {
    $this->actingAs($this->ibuAisyah->user)->getJson("/api/v1/tagihan/{$this->tagihanBima->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('message', 'Data tidak ditemukan.');
});

it('menampilkan detail tagihan dengan riwayat pembayaran dan rekening sekolah ke wali', function () {
    Pengaturan::query()->create([
        'kunci' => 'keuangan.rekening',
        'nilai' => [['bank' => 'Bank Jateng', 'nomor' => '2012345678', 'atas_nama' => 'TK Tarbiyathul Athfal 8']],
        'grup' => 'keuangan',
    ]);
    Pembayaran::factory()->for($this->tagihanAisyah)->create(['metode' => MetodeBayar::Transfer, 'bukti_path' => 'bukti-bayar/aisyah.jpg']);

    $response = $this->actingAs($this->ibuAisyah->user)->getJson("/api/v1/tagihan/{$this->tagihanAisyah->id}")
        ->assertOk()
        ->assertJsonPath('data.rekening.0.nomor', '2012345678')
        ->assertJsonCount(1, 'data.pembayaran');

    expect($response->json('data.pembayaran.0.bukti_url'))->toContain('/api/v1/media/');
});

it('menyembunyikan foto bukti transfer dari guru tanpa izin keuangan', function () {
    Pembayaran::factory()->for($this->tagihanAisyah)->create(['metode' => MetodeBayar::Transfer, 'bukti_path' => 'bukti-bayar/aisyah.jpg']);

    $this->actingAs($this->buAini->user)->getJson("/api/v1/tagihan/{$this->tagihanAisyah->id}")
        ->assertOk()
        ->assertJsonPath('data.pembayaran.0.bukti_url', null);
});

it('memfilter tagihan per status, periode, kelas, dan murid', function () {
    $this->actingAs($this->kepsek);

    $this->getJson('/api/v1/tagihan?filter[status]=terlambat')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->tagihanBima->id);
    $this->getJson('/api/v1/tagihan?filter[periode]=2026-09')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/tagihan?filter[periode]=2026-10')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/tagihan?filter[kelas_id]={$this->kelasA1->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->tagihanAisyah->id);
    $this->getJson("/api/v1/tagihan?filter[murid_id]={$this->bima->id}")->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/tagihan?search=Bima')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->tagihanBima->id);
});
