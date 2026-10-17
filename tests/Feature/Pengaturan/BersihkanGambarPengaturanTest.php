<?php

use App\Models\Pengaturan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sabtu, 17 Oktober 2026 pukul 02:00. Logo, gambar hero, dan satu gambar fasilitas dipakai pengaturan. Dua unggahan
 * tidak pernah disimpan: satu 25 jam lalu, satu 23 jam lalu (form CMS-nya mungkin masih terbuka).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 02:00:00');
    Storage::fake('public');
    $this->dipakai = ['pengaturan/logo.jpg', 'pengaturan/hero.jpg', 'pengaturan/aula.jpg'];
    $this->batal = 'pengaturan/batal-kemarin.jpg';
    $this->baru = 'pengaturan/baru-diunggah.jpg';
    $lama = now()->subHours(25)->getTimestamp();

    foreach ([...$this->dipakai, $this->batal, 'galeri/album-lama.jpg'] as $path) {
        Storage::disk('public')->put($path, 'isi-gambar');
        touch(Storage::disk('public')->path($path), $lama);
    }
    Storage::disk('public')->put($this->baru, 'isi-gambar');
    touch(Storage::disk('public')->path($this->baru), now()->subHours(23)->getTimestamp());

    Pengaturan::query()->updateOrCreate(['kunci' => 'profil.logo'], ['nilai' => 'pengaturan/logo.jpg', 'grup' => 'profil']);
    Pengaturan::query()->updateOrCreate(['kunci' => 'landing.hero'], ['nilai' => ['judul' => 'Belajar sambil bermain', 'gambar' => 'pengaturan/hero.jpg'], 'grup' => 'landing']);
    Pengaturan::query()->updateOrCreate(['kunci' => 'landing.fasilitas'], ['nilai' => [
        ['nama' => 'Aula serbaguna', 'deskripsi' => 'Untuk kegiatan bersama dan pentas.', 'gambar' => 'pengaturan/aula.jpg'],
        ['nama' => 'Taman bermain', 'deskripsi' => 'Ayunan, perosotan, dan bak pasir.', 'gambar' => null],
    ], 'grup' => 'landing']);
});

it('menghapus gambar yang tidak dipakai dan lebih dari sehari, lalu mencatat jumlahnya di log', function () {
    Log::spy();

    $this->artisan('pengaturan:bersihkan-gambar')
        ->expectsOutputToContain('1 gambar pengaturan dihapus.')
        ->assertSuccessful();

    Storage::disk('public')->assertMissing($this->batal);
    Log::shouldHaveReceived('info')->once()->with('Gambar pengaturan yang tidak dipakai dihapus.', ['jumlah' => 1, 'path' => [$this->batal]]);
});

it('tidak menghapus gambar yang dipakai logo, hero, atau fasilitas walau sudah lama', function () {
    $this->artisan('pengaturan:bersihkan-gambar')->assertSuccessful();

    foreach ($this->dipakai as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

it('tidak menghapus unggahan yang belum sehari, dan file di luar folder pengaturan', function () {
    $this->artisan('pengaturan:bersihkan-gambar')->assertSuccessful();

    Storage::disk('public')->assertExists($this->baru);
    Storage::disk('public')->assertExists('galeri/album-lama.jpg');
});

it('menghapus unggahan itu setelah lewat sehari kalau tetap tidak disimpan', function () {
    Carbon::setTestNow('2026-10-17 03:00:01');

    $this->artisan('pengaturan:bersihkan-gambar')
        ->expectsOutputToContain('2 gambar pengaturan dihapus.')
        ->assertSuccessful();

    Storage::disk('public')->assertMissing($this->baru);
});

it('hanya menghitung saat --dry-run', function () {
    Log::spy();

    $this->artisan('pengaturan:bersihkan-gambar --dry-run')
        ->expectsOutputToContain('[dry run] 1 gambar pengaturan akan dihapus.')
        ->assertSuccessful();

    Storage::disk('public')->assertExists($this->batal);
    Log::shouldNotHaveReceived('info');
});

it('menjadwalkan pembersihan gambar setiap hari pukul 02:00', function () {
    $jadwal = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn (Event $event) => [Str::afterLast((string) $event->command, ' ') => $event->expression]);

    expect($jadwal['pengaturan:bersihkan-gambar'])->toBe('0 2 * * *');
});
