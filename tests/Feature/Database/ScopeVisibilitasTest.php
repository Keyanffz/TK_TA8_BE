<?php

use App\Enums\Hubungan;
use App\Enums\StatusRapor;
use App\Enums\TargetPengumuman;
use App\Models\Guru;
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

/**
 * Tahun ajaran aktif: TK A1 (wali kelas Bu Aini) dan TK B1 (didampingi Bu Nurul).
 * Tahun ajaran lalu: TK A1 lama yang juga diampu Bu Aini.
 */
beforeEach(function () {
    $aktif = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $lalu = TahunAjaran::factory()->create(['nama' => '2025/2026']);

    $this->buAini = Guru::factory()->create();
    $this->buNurul = Guru::factory()->create();
    $this->kepsek = User::factory()->superAdmin()->create();
    $this->bendahara = Guru::factory()->kelolaKeuangan()->create()->user;

    $this->kelasA1 = Kelas::factory()->for($aktif)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
    $this->kelasB1 = Kelas::factory()->for($aktif)->create(['nama' => 'TK B1', 'guru_pendamping_id' => $this->buNurul->id]);
    $kelasLama = Kelas::factory()->for($lalu)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);

    $this->aisyah = Murid::factory()->create();
    $this->bima = Murid::factory()->create();
    $this->citra = Murid::factory()->create();
    $this->kelasA1->murid()->attach($this->aisyah);
    $this->kelasB1->murid()->attach($this->bima);
    $kelasLama->murid()->attach($this->citra);

    $this->ibuAisyah = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibuAisyah, ['hubungan' => Hubungan::Ibu]);
});

it('memperlihatkan semua murid ke kepala sekolah', function () {
    expect(Murid::query()->visibleTo($this->kepsek)->count())->toBe(3);
});

it('memperlihatkan ke guru hanya murid kelas yang diampu pada tahun ajaran aktif', function () {
    expect(Murid::query()->visibleTo($this->buAini->user)->pluck('id')->all())->toBe([$this->aisyah->id])
        ->and(Murid::query()->visibleTo($this->buNurul->user)->pluck('id')->all())->toBe([$this->bima->id]);
});

it('memperlihatkan ke wali murid hanya anaknya sendiri', function () {
    expect(Murid::query()->visibleTo($this->ibuAisyah->user)->pluck('id')->all())->toBe([$this->aisyah->id])
        ->and(Murid::query()->visibleTo(WaliMurid::factory()->create()->user)->count())->toBe(0);
});

it('memperlihatkan semua tagihan dan pembayaran ke petugas keuangan, dan hanya milik murid terkait ke guru dan wali', function () {
    $tagihanAisyah = Tagihan::factory()->for($this->aisyah)->create();
    $tagihanBima = Tagihan::factory()->for($this->bima)->create();
    Pembayaran::factory()->for($tagihanAisyah)->create();
    Pembayaran::factory()->for($tagihanBima)->create();

    expect(Tagihan::query()->visibleTo($this->bendahara)->count())->toBe(2)
        ->and(Tagihan::query()->visibleTo($this->kepsek)->count())->toBe(2)
        ->and(Tagihan::query()->visibleTo($this->buAini->user)->pluck('id')->all())->toBe([$tagihanAisyah->id])
        ->and(Tagihan::query()->visibleTo($this->ibuAisyah->user)->pluck('id')->all())->toBe([$tagihanAisyah->id])
        ->and(Pembayaran::query()->visibleTo($this->bendahara)->count())->toBe(2)
        ->and(Pembayaran::query()->visibleTo($this->ibuAisyah->user)->count())->toBe(1);
});

it('memperlihatkan ke wali murid hanya rapor anaknya yang sudah terbit', function () {
    Rapor::factory()->for($this->aisyah)->for($this->kelasA1)->create(['status' => StatusRapor::Diajukan]);
    $terbit = Rapor::factory()->for($this->aisyah)->for($this->kelasA1)->terbit()->create(['semester' => 2]);
    Rapor::factory()->for($this->bima)->for($this->kelasB1)->terbit()->create();

    expect(Rapor::query()->visibleTo($this->ibuAisyah->user)->pluck('id')->all())->toBe([$terbit->id])
        ->and(Rapor::query()->visibleTo($this->buAini->user)->count())->toBe(2)
        ->and(Rapor::query()->visibleTo($this->kepsek)->count())->toBe(3);
});

it('memperlihatkan kegiatan kelas ke guru pengampu dan ke wali murid dari kelas anaknya', function () {
    $kegiatanA1 = KegiatanKelas::factory()->for($this->kelasA1)->for($this->buAini)->create();
    KegiatanKelas::factory()->for($this->kelasB1)->for($this->buNurul)->create();

    expect(KegiatanKelas::query()->visibleTo($this->ibuAisyah->user)->pluck('id')->all())->toBe([$kegiatanA1->id])
        ->and(KegiatanKelas::query()->visibleTo($this->buAini->user)->pluck('id')->all())->toBe([$kegiatanA1->id])
        ->and(KegiatanKelas::query()->visibleTo($this->kepsek)->count())->toBe(2);
});

it('menyusun feed pengumuman wali murid dari target semua, wali murid, kelas anak, dan anaknya', function () {
    $buat = fn (TargetPengumuman $target, array $atribut = []) => Pengumuman::factory()
        ->create(['target' => $target, 'penulis_id' => $this->kepsek->id, ...$atribut]);

    $semua = $buat(TargetPengumuman::Semua);
    $untukWali = $buat(TargetPengumuman::WaliMurid);
    $buat(TargetPengumuman::Guru);
    $kelasAnak = $buat(TargetPengumuman::Kelas);
    $kelasAnak->kelas()->attach($this->kelasA1);
    $buat(TargetPengumuman::Kelas)->kelas()->attach($this->kelasB1);
    $anaknya = $buat(TargetPengumuman::Murid);
    $anaknya->murid()->attach($this->aisyah);
    $buat(TargetPengumuman::Murid)->murid()->attach($this->bima);
    $buat(TargetPengumuman::Semua, ['published_at' => null]);
    $buat(TargetPengumuman::Semua, ['published_at' => now()->addDay()]);

    expect(Pengumuman::query()->visibleTo($this->ibuAisyah->user)->orderBy('id')->pluck('id')->all())
        ->toBe([$semua->id, $untukWali->id, $kelasAnak->id, $anaknya->id])
        ->and(Pengumuman::query()->visibleTo($this->kepsek)->count())->toBe(9);
});

it('menyusun feed pengumuman guru dari target guru, kelas yang diampu, dan draft tulisannya sendiri', function () {
    $untukGuru = Pengumuman::factory()->create(['target' => TargetPengumuman::Guru]);
    $kelasDiampu = Pengumuman::factory()->create(['target' => TargetPengumuman::Kelas]);
    $kelasDiampu->kelas()->attach($this->kelasA1);
    Pengumuman::factory()->create(['target' => TargetPengumuman::WaliMurid]);
    $draftSendiri = Pengumuman::factory()->draft()->create(['penulis_id' => $this->buAini->user_id]);
    Pengumuman::factory()->draft()->create();

    expect(Pengumuman::query()->visibleTo($this->buAini->user)->orderBy('id')->pluck('id')->all())
        ->toBe([$untukGuru->id, $kelasDiampu->id, $draftSendiri->id]);
});

it('memperlihatkan pendaftaran PPDB hanya ke pemiliknya dan kepala sekolah, tidak ke guru', function () {
    $milikIbuAisyah = Pendaftaran::factory()->for($this->ibuAisyah)->create();
    Pendaftaran::factory()->create();

    expect(Pendaftaran::query()->visibleTo($this->ibuAisyah->user)->pluck('id')->all())->toBe([$milikIbuAisyah->id])
        ->and(Pendaftaran::query()->visibleTo($this->kepsek)->count())->toBe(2)
        ->and(Pendaftaran::query()->visibleTo($this->buAini->user)->count())->toBe(0);
});
