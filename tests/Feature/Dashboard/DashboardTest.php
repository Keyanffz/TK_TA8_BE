<?php

use App\Enums\Hubungan;
use App\Enums\MetodeBayar;
use App\Enums\StatusAkun;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\StatusPembayaran;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusRapor;
use App\Enums\StatusTagihan;
use App\Enums\TargetPengumuman;
use App\Models\Agenda;
use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\KegiatanKelas;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Pendaftaran;
use App\Models\Pengumuman;
use App\Models\Rapor;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Support\Carbon;

/**
 * TK A1 (Bu Aini, kapasitas cukup) berisi Aisyah dan Bima; TK B1 (Bu Sri) berisi Citra. Hari ini 15 Oktober 2026.
 * SPP Oktober Rp 150.000 jatuh tempo 10 Oktober: Aisyah lunas, Bima terlambat, Citra menunggu verifikasi.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 09:00:00');
    $this->kepsek = buatKepalaSekolah();
    $this->tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027', 'semester_aktif' => 1]);
    $this->buAini = buatGuru();
    $this->buSri = Guru::factory()->kelolaKeuangan()->create();
    $this->kelasA1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
    $this->kelasB1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK B1', 'wali_kelas_id' => $this->buSri->id]);

    $this->aisyah = Murid::factory()->create(['nama_panggilan' => 'Aisyah']);
    $this->bima = Murid::factory()->create(['nama_panggilan' => 'Bima']);
    $this->citra = Murid::factory()->create(['nama_panggilan' => 'Citra']);
    $this->kelasA1->murid()->attach([$this->aisyah->id, $this->bima->id]);
    $this->kelasB1->murid()->attach($this->citra);
    Murid::factory()->create(['status' => StatusMurid::Lulus, 'tanggal_keluar' => '2026-06-20']);

    $this->ibu = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $this->bima->waliMurid()->attach($this->ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $spp = JenisTagihan::factory()->for($this->tahunAjaran)->create(['nama' => 'SPP', 'nominal' => 150000]);
    $tagihan = fn (Murid $murid, StatusTagihan $status, string $jatuhTempo = '2026-10-10') => Tagihan::factory()->for($murid)->for($spp)->create([
        'periode' => Carbon::parse($jatuhTempo)->startOfMonth()->toDateString(), 'jatuh_tempo' => $jatuhTempo, 'nominal' => 150000, 'total' => 150000, 'status' => $status,
    ]);
    $this->sppAisyah = $tagihan($this->aisyah, StatusTagihan::Lunas);
    $this->sppBima = $tagihan($this->bima, StatusTagihan::Terlambat);
    $this->sppCitra = $tagihan($this->citra, StatusTagihan::MenungguVerifikasi);
    $this->sppBimaNovember = $tagihan($this->bima, StatusTagihan::BelumBayar, '2026-11-10');
    Pembayaran::factory()->for($this->sppAisyah)->create(['metode' => MetodeBayar::Tunai, 'jumlah' => 150000, 'tanggal_bayar' => '2026-10-03', 'status' => StatusPembayaran::Diterima]);
    Pembayaran::factory()->for($this->sppCitra)->create(['jumlah' => 150000, 'tanggal_bayar' => '2026-10-12', 'status' => StatusPembayaran::Menunggu]);
});

it('mengisi beranda Kepala Sekolah dengan statistik, keuangan, grafik, dan tindakan tertunda', function () {
    buatGuru(StatusAkun::Pending);
    User::factory()->waliMurid()->status(StatusAkun::Nonaktif)->create();
    Rapor::factory()->for($this->citra)->for($this->kelasB1)->create(['status' => StatusRapor::Diajukan, 'dibuat_oleh' => $this->buSri->id]);
    Pendaftaran::factory()->for($this->ibu)->create();
    Pendaftaran::factory()->for($this->ibu)->create(['status' => StatusPendaftaran::Ditolak]);
    Pengumuman::factory()->create(['penulis_id' => $this->kepsek->id, 'judul' => 'Libur Maulid']);
    Agenda::factory()->create(['tanggal_mulai' => '2026-10-22', 'tanggal_selesai' => '2026-10-22']);
    Agenda::factory()->create(['tanggal_mulai' => '2026-10-01', 'tanggal_selesai' => '2026-10-01']);

    $response = $this->actingAs($this->kepsek)->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.statistik', ['murid_aktif' => 3, 'guru_aktif' => 2, 'kelas' => 2, 'wali_murid' => 1])
        ->assertJsonPath('data.keuangan_bulan_ini', ['total_tagihan' => 450000, 'terbayar' => 150000, 'belum_terbayar' => 300000, 'persen_lunas' => 33.3])
        ->assertJsonPath('data.tertunda', ['guru_pending' => 1, 'pembayaran_menunggu' => 1, 'rapor_diajukan' => 1, 'pendaftaran_baru' => 1])
        ->assertJsonCount(1, 'data.pengumuman_terbaru')
        ->assertJsonCount(1, 'data.agenda_mendatang');

    $grafik = $response->json('data.grafik_pemasukan');
    expect($grafik)->toHaveCount(12)
        ->and($grafik[0]['bulan'])->toBe('2025-11')
        ->and($grafik[11])->toBe(['bulan' => '2026-10', 'total' => 150000]);
});

it('mengisi beranda guru dengan kelas yang diampu, progres rapor, dan keuangan kelas', function () {
    Rapor::factory()->for($this->aisyah)->for($this->kelasA1)->create(['status' => StatusRapor::Draft]);
    Rapor::factory()->for($this->bima)->for($this->kelasA1)->terbit()->create();
    Rapor::factory()->for($this->bima)->for($this->kelasA1)->create(['semester' => 2, 'status' => StatusRapor::Draft]);
    KegiatanKelas::factory()->for($this->kelasA1)->create(['guru_id' => $this->buAini->id]);
    KegiatanKelas::factory()->for($this->kelasB1)->create(['guru_id' => $this->buSri->id]);
    Pengumuman::factory()->create(['penulis_id' => $this->kepsek->id, 'target' => TargetPengumuman::WaliMurid]);

    $this->actingAs($this->buAini->user)->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.kelas_saya', [['id' => $this->kelasA1->id, 'nama' => 'TK A1', 'jumlah_murid' => 2]])
        ->assertJsonPath('data.progres_rapor', ['total' => 2, 'draft' => 1, 'diajukan' => 0, 'revisi' => 0, 'terbit' => 1])
        ->assertJsonCount(1, 'data.kegiatan_terbaru')
        ->assertJsonCount(0, 'data.pengumuman_terbaru')
        ->assertJsonPath('data.keuangan_kelas', ['lunas' => 1, 'belum' => 1])
        ->assertJsonPath('data.pembayaran_menunggu', null);
});

it('menampilkan jumlah pembayaran menunggu hanya untuk guru berizin keuangan', function () {
    $this->actingAs($this->buSri->user)->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.pembayaran_menunggu', 1)
        ->assertJsonPath('data.keuangan_kelas', ['lunas' => 0, 'belum' => 1]);
});

it('mengisi beranda wali untuk anak pertama atau anak yang dipilih', function () {
    Rapor::factory()->for($this->bima)->for($this->kelasA1)->terbit()->create();
    Rapor::factory()->for($this->aisyah)->for($this->kelasA1)->create(['status' => StatusRapor::Diajukan]);
    KegiatanKelas::factory()->for($this->kelasA1)->create(['guru_id' => $this->buAini->id]);
    KegiatanKelas::factory()->for($this->kelasB1)->create(['guru_id' => $this->buSri->id]);

    $this->actingAs($this->ibu->user)->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.anak.nama_panggilan', 'Aisyah')
        ->assertJsonPath('data.anak.kelas.nama', 'TK A1')
        ->assertJsonCount(0, 'data.tagihan_aktif')
        ->assertJsonPath('data.total_belum_bayar', 0)
        ->assertJsonCount(1, 'data.kegiatan_terbaru')
        ->assertJsonPath('data.rapor_terbaru', null);

    $this->actingAs($this->ibu->user)->getJson("/api/v1/dashboard?murid_id={$this->bima->id}")
        ->assertOk()
        ->assertJsonPath('data.anak.nama_panggilan', 'Bima')
        ->assertJsonCount(2, 'data.tagihan_aktif')
        ->assertJsonPath('data.tagihan_aktif.0.id', $this->sppBima->id)
        ->assertJsonPath('data.total_belum_bayar', 300000)
        ->assertJsonPath('data.rapor_terbaru.status', 'terbit');
});

it('membalas 404 saat wali memilih anak orang lain di beranda', function () {
    $this->actingAs($this->ibu->user)->getJson("/api/v1/dashboard?murid_id={$this->citra->id}")->assertNotFound();
});

it('mengisi beranda wali tanpa anak tertaut dengan anak null', function () {
    $this->actingAs(WaliMurid::factory()->create()->user)->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.anak', null)
        ->assertJsonPath('data.tagihan_aktif', [])
        ->assertJsonPath('data.rapor_terbaru', null);
});

it('tidak menghitung penempatan yang sudah tidak aktif di kelas guru', function () {
    $this->kelasA1->murid()->updateExistingPivot($this->bima->id, ['status' => StatusKelasMurid::Keluar]);

    $this->actingAs($this->buAini->user)->getJson('/api/v1/dashboard')
        ->assertJsonPath('data.kelas_saya.0.jumlah_murid', 1)
        ->assertJsonPath('data.keuangan_kelas', ['lunas' => 1, 'belum' => 0]);
});
