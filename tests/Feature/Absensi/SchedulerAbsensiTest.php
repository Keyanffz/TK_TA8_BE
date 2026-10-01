<?php

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Enums\StatusAkun;
use App\Models\Absensi;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Kamis, 1 Oktober 2026 pukul 09:10, sepuluh menit setelah jam masuk tutup (09:00). Bu Nur sudah absen,
 * Bu Dwi belum, Bu Fitri nonaktif, dan Kepala Sekolah belum absen. Semua akun dibuat pagi itu, jadi tidak ada
 * hari sebelumnya yang perlu diisi susulan kecuali di test susulan, yang memundurkan tanggal pembuatan akun.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-01 06:00:00');
    Storage::fake('local');
    aturAbsensi(['tanggal_mulai' => '2026-09-01']);
    $this->kepsek = buatKepalaSekolah();
    $this->nur = buatGuru()->user;
    $this->dwi = buatGuru()->user;
    $this->fitri = buatGuru(StatusAkun::Nonaktif)->user;

    Carbon::setTestNow('2026-10-01 09:10:00');
    Absensi::factory()->for($this->nur)->create(['tanggal' => '2026-10-01', 'status' => StatusAbsensi::Terlambat]);
});

it('menandai tidak hadir peserta aktif yang belum absen masuk setelah jam masuk tutup', function () {
    $this->artisan('absensi:tandai-tidak-hadir')
        ->expectsOutputToContain('2 absensi tidak hadir dicatat.')
        ->assertSuccessful();

    $dwi = Absensi::query()->where('user_id', $this->dwi->id)->sole();
    expect($dwi->status)->toBe(StatusAbsensi::TidakHadir)
        ->and($dwi->jenis)->toBe(JenisAbsensi::Masuk)
        ->and($dwi->tanggal->toDateString())->toBe('2026-10-01')
        ->and($dwi->waktu)->toBeNull()
        ->and($dwi->foto_path)->toBeNull()
        ->and(Absensi::query()->where('user_id', $this->kepsek->id)->sole()->status)->toBe(StatusAbsensi::TidakHadir)
        ->and(Absensi::query()->where('user_id', $this->nur->id)->sole()->status)->toBe(StatusAbsensi::Terlambat);
});

it('tidak menandai guru nonaktif dan wali murid', function () {
    $wali = User::factory()->waliMurid()->create();

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->whereIn('user_id', [$this->fitri->id, $wali->id])->count())->toBe(0);
});

it('tidak membuat baris ganda saat dijalankan berulang', function () {
    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();
    Carbon::setTestNow('2026-10-01 09:20:00');

    $this->artisan('absensi:tandai-tidak-hadir')
        ->expectsOutputToContain('0 absensi tidak hadir dicatat.')
        ->assertSuccessful();

    expect(Absensi::query()->count())->toBe(3);
});

it('tidak menandai hari ini sebelum jam masuk tutup, di tanggal libur, atau di luar hari kerja', function (Closure $siapkan) {
    $siapkan();

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->where('status', StatusAbsensi::TidakHadir)->whereDate('tanggal', today()->toDateString())->count())->toBe(0);
})->with([
    'tepat jam tutup' => [fn () => Carbon::setTestNow('2026-10-01 09:00:50')],
    'tanggal libur' => [fn () => aturAbsensi(['tanggal_libur' => ['2026-10-01']])],
    'hari Minggu' => [fn () => Carbon::setTestNow('2026-10-04 09:10:00')],
    'Sabtu dikeluarkan dari hari kerja' => [function () {
        aturAbsensi(['hari_kerja' => [1, 2, 3, 4, 5]]);
        Carbon::setTestNow('2026-10-03 09:10:00');
    }],
]);

it('mengikuti jam masuk tutup yang diatur Kepala Sekolah', function () {
    aturAbsensi(['jam_masuk' => ['buka' => '06:30', 'batas_terlambat' => '07:15', 'tutup' => '10:00']]);

    $this->artisan('absensi:tandai-tidak-hadir')->expectsOutputToContain('0 absensi tidak hadir dicatat.');

    Carbon::setTestNow('2026-10-01 10:01:00');
    $this->artisan('absensi:tandai-tidak-hadir')->expectsOutputToContain('2 absensi tidak hadir dicatat.');
});

it('tidak menandai akun yang baru dibuat setelah jam masuk tutup', function () {
    $baru = buatGuru()->user;

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->where('user_id', $baru->id)->exists())->toBeFalse();
});

it('hanya menghitung saat --dry-run', function () {
    $this->artisan('absensi:tandai-tidak-hadir', ['--dry-run' => true])
        ->expectsOutputToContain('[dry run] 2 absensi tidak hadir akan dicatat.')
        ->assertSuccessful();

    expect(Absensi::query()->count())->toBe(1);
});

/**
 * Scheduler mati sejak Rabu, 23 September. Dijalankan lagi Kamis, 1 Oktober pukul 09:10: susulan menjangkau
 * 24 September sampai 30 September. Minggu 27 September bukan hari kerja dan Senin 28 September tanggal libur.
 */
function siapkanSusulan(object $test): void
{
    User::query()->update(['created_at' => '2026-09-01 08:00:00']);
    aturAbsensi(['tanggal_libur' => ['2026-09-28']]);
    Absensi::factory()->for($test->dwi)->create(['tanggal' => '2026-09-29', 'status' => StatusAbsensi::Hadir]);
}

/**
 * @return list<string>
 */
function tanggalTidakHadir(User $user): array
{
    return Absensi::query()->where('user_id', $user->id)->where('status', StatusAbsensi::TidakHadir)
        ->orderBy('tanggal')->get()->map(fn (Absensi $absensi) => $absensi->tanggal->toDateString())->all();
}

it('mengisi susulan hari kerja yang terlewat sampai 7 hari ke belakang', function () {
    siapkanSusulan($this);

    $this->artisan('absensi:tandai-tidak-hadir')
        ->expectsOutputToContain('16 absensi tidak hadir dicatat.')
        ->assertSuccessful();

    expect(tanggalTidakHadir($this->nur))->toBe(['2026-09-24', '2026-09-25', '2026-09-26', '2026-09-29', '2026-09-30'])
        ->and(tanggalTidakHadir($this->dwi))->toBe(['2026-09-24', '2026-09-25', '2026-09-26', '2026-09-30', '2026-10-01'])
        ->and(tanggalTidakHadir($this->kepsek))->toBe(['2026-09-24', '2026-09-25', '2026-09-26', '2026-09-29', '2026-09-30', '2026-10-01'])
        ->and(tanggalTidakHadir($this->fitri))->toBe([])
        ->and(Absensi::query()->where('user_id', $this->dwi->id)->whereDate('tanggal', '2026-09-29')->sole()->status)->toBe(StatusAbsensi::Hadir);
});

it('melewati hari libur dan hari di luar hari kerja saat mengisi susulan', function () {
    siapkanSusulan($this);

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->whereDate('tanggal', '2026-09-27')->count())->toBe(0)
        ->and(Absensi::query()->whereDate('tanggal', '2026-09-28')->count())->toBe(0);
});

it('tidak menyentuh hari yang lebih dari 7 hari ke belakang', function () {
    siapkanSusulan($this);

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->whereDate('tanggal', '<', '2026-09-24')->count())->toBe(0)
        ->and(Absensi::query()->whereDate('tanggal', '2026-09-24')->count())->toBe(3);
});

it('tidak membuat baris dobel saat susulan dijalankan dua kali', function () {
    siapkanSusulan($this);
    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();
    $setelahPertama = Absensi::query()->count();

    $this->artisan('absensi:tandai-tidak-hadir')
        ->expectsOutputToContain('0 absensi tidak hadir dicatat.')
        ->assertSuccessful();

    $dobel = Absensi::query()->selectRaw('user_id, tanggal, jenis, count(*) as jumlah')
        ->groupBy('user_id', 'tanggal', 'jenis')->having('jumlah', '>', 1)->get();
    expect(Absensi::query()->count())->toBe($setelahPertama)
        ->and($setelahPertama)->toBe(18)
        ->and($dobel)->toBeEmpty();
});

it('tidak mengisi susulan untuk tanggal sebelum tanggal mulai absensi', function () {
    siapkanSusulan($this);
    aturAbsensi(['tanggal_mulai' => '2026-09-26']);

    $this->artisan('absensi:tandai-tidak-hadir')
        ->expectsOutputToContain('10 absensi tidak hadir dicatat.')
        ->assertSuccessful();

    expect(tanggalTidakHadir($this->nur))->toBe(['2026-09-26', '2026-09-29', '2026-09-30'])
        ->and(tanggalTidakHadir($this->kepsek))->toBe(['2026-09-26', '2026-09-29', '2026-09-30', '2026-10-01'])
        ->and(Absensi::query()->whereDate('tanggal', '<', '2026-09-26')->count())->toBe(0);
});

it('tidak menandai siapa pun kalau absensi baru mulai besok', function () {
    siapkanSusulan($this);
    aturAbsensi(['tanggal_mulai' => '2026-10-02']);

    $this->artisan('absensi:tandai-tidak-hadir')
        ->expectsOutputToContain('0 absensi tidak hadir dicatat.')
        ->assertSuccessful();

    expect(Absensi::query()->where('status', StatusAbsensi::TidakHadir)->count())->toBe(0);
});

it('mengisi susulan hari kemarin walau hari ini jam masuk belum tutup', function () {
    siapkanSusulan($this);
    Carbon::setTestNow('2026-10-01 08:00:00');

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(tanggalTidakHadir($this->dwi))->toBe(['2026-09-24', '2026-09-25', '2026-09-26', '2026-09-30']);
});

it('tidak menandai akun pada hari sebelum akun itu dibuat', function () {
    siapkanSusulan($this);
    $this->dwi->forceFill(['created_at' => '2026-09-30 06:00:00'])->save();

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(tanggalTidakHadir($this->dwi))->toBe(['2026-09-30', '2026-10-01']);
});

it('menghapus file foto yang melewati masa simpan dan mempertahankan data absensinya', function () {
    $fotoLama = 'absensi/lama.jpg';
    $fotoBaru = 'absensi/baru.jpg';
    Storage::disk('local')->put($fotoLama, 'foto');
    Storage::disk('local')->put($fotoBaru, 'foto');
    $lama = Absensi::factory()->for($this->dwi)->create(['tanggal' => '2026-03-31', 'foto_path' => $fotoLama]);
    $baru = Absensi::factory()->for($this->dwi)->create(['tanggal' => '2026-04-01', 'foto_path' => $fotoBaru]);

    $this->artisan('absensi:hapus-foto-lama', ['--dry-run' => true])
        ->expectsOutputToContain('[dry run] 1 foto absensi akan dihapus.');
    Storage::disk('local')->assertExists($fotoLama);

    $this->artisan('absensi:hapus-foto-lama')
        ->expectsOutputToContain('1 foto absensi dihapus.')
        ->assertSuccessful();

    Storage::disk('local')->assertMissing($fotoLama);
    Storage::disk('local')->assertExists($fotoBaru);
    expect($lama->fresh()?->foto_path)->toBeNull()
        ->and($lama->fresh()?->status)->toBe(StatusAbsensi::Hadir)
        ->and($baru->fresh()?->foto_path)->toBe($fotoBaru);
});

it('mengikuti masa simpan foto yang diatur Kepala Sekolah', function () {
    aturAbsensi(['masa_simpan_foto_bulan' => 1]);
    Storage::disk('local')->put('absensi/agustus.jpg', 'foto');
    $agustus = Absensi::factory()->for($this->dwi)->create(['tanggal' => '2026-08-31', 'foto_path' => 'absensi/agustus.jpg']);

    $this->artisan('absensi:hapus-foto-lama')->assertSuccessful();

    Storage::disk('local')->assertMissing('absensi/agustus.jpg');
    expect($agustus->fresh()?->foto_path)->toBeNull();
});

it('menjadwalkan kedua command absensi', function () {
    $jadwal = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn (Event $event) => [Str::afterLast((string) $event->command, ' ') => $event->expression]);

    expect($jadwal['absensi:tandai-tidak-hadir'])->toBe('*/10 * * * *')
        ->and($jadwal['absensi:hapus-foto-lama'])->toBe('0 1 * * *');
});
