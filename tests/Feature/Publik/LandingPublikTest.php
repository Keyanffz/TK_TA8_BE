<?php

use App\Enums\JenisAgenda;
use App\Enums\StatusAkun;
use App\Enums\TargetPengumuman;
use App\Models\Agenda;
use App\Models\GaleriAlbum;
use App\Models\GaleriFoto;
use App\Models\Guru;
use App\Models\Pengumuman;
use App\Models\User;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    Storage::fake('public');
});

it('menampilkan pengaturan profil dan landing tanpa login, tanpa grup keuangan dan ppdb', function () {
    $this->seed(PengaturanSeeder::class);

    $data = $this->getJson('/api/v1/public/profil')->assertOk()->json('data');

    expect($data['profil.nama_sekolah'])->toBe('TK Tarbiyathul Athfal 8')
        ->and($data)->toHaveKey('profil.logo_url')
        ->and($data['landing.hero'])->toHaveKey('gambar_url')
        ->and(collect($data)->keys()->filter(fn (string $kunci) => str_starts_with($kunci, 'keuangan.') || str_starts_with($kunci, 'ppdb.'))->all())->toBe([]);
});

it('hanya menampilkan pengumuman publik yang sudah terbit', function () {
    $publik = Pengumuman::factory()->create(['is_publik' => true, 'slug' => 'libur-maulid-nabi']);
    Pengumuman::factory()->create(['is_publik' => false]);
    $draft = Pengumuman::factory()->draft()->create(['is_publik' => true]);
    $terjadwal = Pengumuman::factory()->create(['is_publik' => true, 'published_at' => '2026-10-10 08:00:00']);
    Pengumuman::factory()->create(['is_publik' => false, 'target' => TargetPengumuman::Guru]);

    $this->getJson('/api/v1/public/pengumuman')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'libur-maulid-nabi')
        ->assertJsonMissingPath('data.0.penulis');

    $this->getJson('/api/v1/public/pengumuman/libur-maulid-nabi')->assertOk()->assertJsonPath('data.id', $publik->id);
    $this->getJson("/api/v1/public/pengumuman/{$draft->slug}")->assertNotFound();
    $this->getJson("/api/v1/public/pengumuman/{$terjadwal->slug}")->assertNotFound();
});

it('hanya menampilkan agenda publik di bulan yang diminta', function () {
    Agenda::factory()->create(['judul' => 'Kunjungan ke Semarang Zoo', 'tanggal_mulai' => '2026-10-15', 'tanggal_selesai' => '2026-10-15']);
    Agenda::factory()->create(['judul' => 'Rapat guru', 'jenis' => JenisAgenda::Rapat, 'is_publik' => false, 'tanggal_mulai' => '2026-10-31', 'tanggal_selesai' => '2026-10-31']);

    $this->getJson('/api/v1/public/agenda')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.judul', 'Kunjungan ke Semarang Zoo');
    $this->getJson('/api/v1/public/agenda?bulan=2026-11')->assertOk()->assertJsonCount(0, 'data');
});

it('hanya menampilkan album galeri publik beserta fotonya', function () {
    $publik = GaleriAlbum::factory()->create(['slug' => 'karnaval-kemerdekaan', 'is_publik' => true]);
    GaleriFoto::factory()->for($publik, 'album')->create(['urutan' => 2, 'path' => 'galeri/b.jpg']);
    GaleriFoto::factory()->for($publik, 'album')->create(['urutan' => 1, 'path' => 'galeri/a.jpg']);
    $tertutup = GaleriAlbum::factory()->create(['is_publik' => false]);

    $this->getJson('/api/v1/public/galeri')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.jumlah_foto', 2)
        ->assertJsonPath('data.0.cover_url', Storage::disk('public')->url('galeri/a.jpg'));

    $this->getJson('/api/v1/public/galeri/karnaval-kemerdekaan')
        ->assertOk()
        ->assertJsonCount(2, 'data.foto')
        ->assertJsonPath('data.foto.0.url', Storage::disk('public')->url('galeri/a.jpg'));
    $this->getJson("/api/v1/public/galeri/{$tertutup->slug}")->assertNotFound();
});

it('menampilkan guru aktif yang tampil di landing, Kepala Sekolah lebih dulu', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->guru->update(['tampil_di_landing' => true]);
    Guru::factory()->for(User::factory()->state(['name' => 'Siti Rahmawati']))->create(['tampil_di_landing' => true, 'jabatan' => 'Guru Kelas']);
    Guru::factory()->for(User::factory()->state(['name' => 'Aini Nur']))->create(['tampil_di_landing' => true]);
    Guru::factory()->create(['tampil_di_landing' => false]);
    buatGuru(StatusAkun::Nonaktif, ['tampil_di_landing' => true]);

    $this->getJson('/api/v1/public/guru')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.nama', $kepsek->name)
        ->assertJsonPath('data.0.jabatan', 'Kepala Sekolah')
        ->assertJsonPath('data.1.nama', 'Aini Nur')
        ->assertJsonPath('data.2.nama', 'Siti Rahmawati')
        ->assertJsonPath('data.2.jabatan', 'Guru Kelas');
});
