<?php

use App\Enums\Hubungan;
use App\Enums\JenisDokumen;
use App\Enums\Role;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusPembayaran;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusTagihan;
use App\Enums\TargetPengumuman;
use App\Models\ElemenPenilaian;
use App\Models\GaleriAlbum;
use App\Models\GaleriFoto;
use App\Models\Guru;
use App\Models\KegiatanFoto;
use App\Models\KegiatanKelas;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Pendaftaran;
use App\Models\PendaftaranDokumen;
use App\Models\Pengaturan;
use App\Models\Pengumuman;
use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Models\Tagihan;
use App\Models\WaliMurid;
use Illuminate\Database\QueryException;

it('menghubungkan profil guru dan wali murid ke akunnya', function () {
    $guru = Guru::factory()->create();
    $wali = WaliMurid::factory()->create();

    expect($guru->user->guru->is($guru))->toBeTrue()
        ->and($wali->user->waliMurid->is($wali))->toBeTrue();
});

it('mencatat hubungan dan kontak utama saat murid punya ayah dan ibu sebagai wali', function () {
    $murid = Murid::factory()->create();
    $ayah = WaliMurid::factory()->create();
    $ibu = WaliMurid::factory()->create();

    $murid->waliMurid()->attach($ayah, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);
    $murid->waliMurid()->attach($ibu, ['hubungan' => Hubungan::Ibu]);

    $wali = $murid->waliMurid()->orderBy('wali_murid.id')->get();

    expect($wali)->toHaveCount(2)
        ->and($wali[0]->pivot->hubungan)->toBe(Hubungan::Ayah)
        ->and($wali[0]->pivot->is_kontak_utama)->toBeTrue()
        ->and($wali[1]->pivot->hubungan)->toBe(Hubungan::Ibu)
        ->and($wali[1]->pivot->created_at)->not->toBeNull()
        ->and($ibu->murid()->first()?->is($murid))->toBeTrue();
});

it('menolak tautan wali yang sama ke murid yang sama dua kali', function () {
    $murid = Murid::factory()->create();
    $wali = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu]);

    expect(fn () => $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu]))
        ->toThrow(QueryException::class);
});

it('menyimpan penempatan murid di kelas beserta statusnya', function () {
    $waliKelas = Guru::factory()->create();
    $pendamping = Guru::factory()->create();
    $kelas = Kelas::factory()->create(['wali_kelas_id' => $waliKelas->id, 'guru_pendamping_id' => $pendamping->id]);
    $murid = Murid::factory()->create();

    $kelas->murid()->attach($murid, ['status' => StatusKelasMurid::Aktif]);

    $penempatan = $murid->kelas()->first();

    expect($penempatan?->is($kelas))->toBeTrue()
        ->and($penempatan?->pivot->status)->toBe(StatusKelasMurid::Aktif)
        ->and($murid->kelasMurid()->first()?->kelas->tahunAjaran->is($kelas->tahunAjaran))->toBeTrue()
        ->and($waliKelas->kelasWali()->first()?->is($kelas))->toBeTrue()
        ->and($pendamping->kelasPendamping()->first()?->is($kelas))->toBeTrue();
});

it('menghubungkan tagihan ke murid, jenis tagihan, tahun ajaran, dan pembayarannya', function () {
    $tagihan = Tagihan::factory()->create();
    Pembayaran::factory()->for($tagihan)->create(['status' => StatusPembayaran::Ditolak]);
    Pembayaran::factory()->for($tagihan)->create();

    $tagihan->refresh();

    expect($tagihan->status)->toBe(StatusTagihan::BelumBayar)
        ->and($tagihan->total)->toBe(150000)
        ->and($tagihan->tahun_ajaran_id)->toBe($tagihan->jenisTagihan->tahun_ajaran_id)
        ->and($tagihan->murid->tagihan()->first()?->is($tagihan))->toBeTrue()
        ->and($tagihan->pembayaran()->pluck('status')->all())->toBe([StatusPembayaran::Ditolak, StatusPembayaran::Menunggu])
        ->and($tagihan->pembayaran()->first()?->jumlah)->toBe($tagihan->total);
});

it('menolak tagihan kedua untuk murid, jenis tagihan, dan periode yang sama', function () {
    $tagihan = Tagihan::factory()->create();

    expect(fn () => Tagihan::factory()->create([
        'murid_id' => $tagihan->murid_id,
        'jenis_tagihan_id' => $tagihan->jenis_tagihan_id,
        'periode' => $tagihan->periode,
    ]))->toThrow(QueryException::class);
});

it('menyimpan rapor dengan detail per elemen penilaian dan ukuran tubuh satu desimal', function () {
    $rapor = Rapor::factory()->create(['tinggi_badan' => 108.46, 'berat_badan' => 18]);
    $elemen = ElemenPenilaian::factory()->count(3)->create();
    $elemen->each(fn (ElemenPenilaian $e) => RaporDetail::factory()->for($rapor)->for($e)->create());

    $rapor->refresh();

    expect($rapor->detail)->toHaveCount(3)
        ->and($rapor->tinggi_badan)->toBe('108.5')
        ->and($rapor->berat_badan)->toBe('18.0')
        ->and($rapor->tahun_ajaran_id)->toBe($rapor->kelas->tahun_ajaran_id)
        ->and($rapor->pembuat->rapor()->first()?->is($rapor))->toBeTrue();

    expect(fn () => RaporDetail::factory()->for($rapor)->for($elemen[0])->create())
        ->toThrow(QueryException::class);
});

it('menyimpan target kelas dan murid pada pengumuman', function () {
    $pengumuman = Pengumuman::factory()->create(['target' => TargetPengumuman::Kelas]);
    $kelas = Kelas::factory()->count(2)->create();
    $murid = Murid::factory()->create();

    $pengumuman->kelas()->attach($kelas);
    $pengumuman->murid()->attach($murid);

    expect($pengumuman->kelas)->toHaveCount(2)
        ->and($pengumuman->murid->first()?->is($murid))->toBeTrue()
        ->and($kelas[0]->pengumuman->first()?->is($pengumuman))->toBeTrue()
        ->and($pengumuman->penulis->role)->toBe(Role::SuperAdmin);
});

it('menghubungkan pendaftaran PPDB ke wali, dokumen, dan murid yang dibuat saat diterima', function () {
    $pendaftaran = Pendaftaran::factory()->create();
    PendaftaranDokumen::factory()->for($pendaftaran)->create(['jenis' => JenisDokumen::KartuKeluarga]);
    $murid = Murid::factory()->create();

    $pendaftaran->update(['status' => StatusPendaftaran::Diterima, 'murid_id' => $murid->id]);

    expect($pendaftaran->dokumen->first()?->jenis)->toBe(JenisDokumen::KartuKeluarga)
        ->and($pendaftaran->waliMurid->pendaftaran->first()?->is($pendaftaran))->toBeTrue()
        ->and($murid->pendaftaran?->is($pendaftaran))->toBeTrue();
});

it('mengurutkan foto kegiatan dan foto galeri berdasarkan kolom urutan', function () {
    $kegiatan = KegiatanKelas::factory()->create();
    KegiatanFoto::factory()->for($kegiatan, 'kegiatan')->create(['urutan' => 2, 'caption' => 'kedua']);
    KegiatanFoto::factory()->for($kegiatan, 'kegiatan')->create(['urutan' => 1, 'caption' => 'pertama']);
    $album = GaleriAlbum::factory()->create();
    GaleriFoto::factory()->for($album, 'album')->create(['urutan' => 2, 'caption' => 'kedua']);
    GaleriFoto::factory()->for($album, 'album')->create(['urutan' => 1, 'caption' => 'pertama']);

    expect($kegiatan->foto->pluck('caption')->all())->toBe(['pertama', 'kedua'])
        ->and($album->foto->pluck('caption')->all())->toBe(['pertama', 'kedua']);
});

it('menyimpan nilai pengaturan JSON sesuai tipe aslinya', function () {
    $nilai = [
        'profil.misi' => ['Membiasakan ibadah sejak dini.', 'Belajar lewat bermain.'],
        'keuangan.tanggal_jatuh_tempo' => 10,
        'ppdb.dibuka' => false,
        'ppdb.tahun_ajaran_id' => null,
    ];

    foreach ($nilai as $kunci => $isi) {
        Pengaturan::query()->create(['kunci' => $kunci, 'nilai' => $isi, 'grup' => strstr($kunci, '.', true)]);
    }

    foreach ($nilai as $kunci => $isi) {
        expect(Pengaturan::query()->where('kunci', $kunci)->value('nilai'))->toBe($isi);
    }
});
