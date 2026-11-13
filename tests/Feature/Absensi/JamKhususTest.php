<?php

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Jumat punya jam khusus: masuk 06:30–08:00 (terlambat setelah 07:00), pulang 10:00–11:30. Hari kerja lain memakai
 * jam bawaan: masuk 06:30–09:00 (terlambat setelah 07:15), pulang 11:00–15:00. Kamis 1 dan Jumat 2 Oktober 2026.
 */
const JAM_KHUSUS_JUMAT = [
    'hari' => 5,
    'jam_masuk' => ['buka' => '06:30', 'batas_terlambat' => '07:00', 'tutup' => '08:00'],
    'jam_pulang' => ['buka' => '10:00', 'tutup' => '11:30'],
];

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 06:00:00');
    Storage::fake('local');
    aturAbsensi([
        'lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH],
        'tanggal_mulai' => '2026-09-01',
        'jam_khusus' => [JAM_KHUSUS_JUMAT],
    ]);
    $this->guru = buatGuru()->user;
});

it('menentukan terlambat dari batas terlambat jam khusus hari itu', function (string $waktu, string $status) {
    Carbon::setTestNow($waktu);

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertCreated()
        ->assertJsonPath('data.status', $status);
})->with([
    'Jumat 07:10, lewat batas khusus 07:00' => ['2026-10-02 07:10:00', 'terlambat'],
    'Kamis 07:10, masih sebelum batas bawaan 07:15' => ['2026-10-01 07:10:00', 'hadir'],
]);

it('menampilkan jam khusus hari ini dan jam terbukanya di status hari ini', function () {
    Carbon::setTestNow('2026-10-02 08:30:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertOk()
        ->assertJsonPath('data.masuk', ['buka' => '06:30', 'batas_terlambat' => '07:00', 'tutup' => '08:00', 'terbuka' => false, 'absensi' => null])
        ->assertJsonPath('data.pulang.buka', '10:00')
        ->assertJsonPath('data.pulang.tutup', '11:30')
        ->assertJsonPath('data.pulang.terbuka', false);

    Carbon::setTestNow('2026-10-02 11:00:00');
    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')->assertJsonPath('data.pulang.terbuka', true);
});

it('memakai jam bawaan di status hari ini untuk hari tanpa jam khusus', function () {
    Carbon::setTestNow('2026-10-01 08:30:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertOk()
        ->assertJsonPath('data.masuk', ['buka' => '06:30', 'batas_terlambat' => '07:15', 'tutup' => '09:00', 'terbuka' => true, 'absensi' => null])
        ->assertJsonPath('data.pulang.buka', '11:00')
        ->assertJsonPath('data.pulang.tutup', '15:00');
});

it('menolak absen masuk setelah jam tutup khusus walau jam bawaan masih terbuka', function () {
    Carbon::setTestNow('2026-10-02 08:30:00');

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Absen masuk hanya bisa pukul 06:30–08:00. Sekarang pukul 08:30.');
    expect(Absensi::query()->count())->toBe(0);
});

it('menerima absen pulang biasa setelah jam pulang khusus buka, tanpa keterangan', function () {
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-10-02', 'waktu' => '2026-10-02 06:50:00']);
    Carbon::setTestNow('2026-10-02 10:30:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertJsonPath('data.pulang.terbuka', true)
        ->assertJsonPath('data.pulang.boleh_lebih_awal', false);
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertCreated()
        ->assertJsonPath('data.pulang_awal', false);
});

it('menganggap pulang lebih awal menurut jam pulang bawaan di hari tanpa jam khusus', function () {
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:50:00']);
    Carbon::setTestNow('2026-10-01 10:30:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertJsonPath('data.pulang.terbuka', false)
        ->assertJsonPath('data.pulang.boleh_lebih_awal', true);
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Absen pulang baru bisa mulai pukul 11:00. Kalau perlu pulang lebih awal, tulis alasannya di keterangan.');
});

it('menawarkan pulang lebih awal sebelum jam pulang khusus buka', function () {
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-10-02', 'waktu' => '2026-10-02 06:50:00']);
    Carbon::setTestNow('2026-10-02 09:30:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')->assertJsonPath('data.pulang.boleh_lebih_awal', true);
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Absen pulang baru bisa mulai pukul 10:00. Kalau perlu pulang lebih awal, tulis alasannya di keterangan.');
});

it('menandai tidak hadir setelah jam masuk khusus tutup, sebelum jam tutup bawaan', function () {
    Carbon::setTestNow('2026-10-02 08:10:00');

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->where('user_id', $this->guru->id)->whereDate('tanggal', '2026-10-02')->sole()->status)->toBe(StatusAbsensi::TidakHadir);
});

it('belum menandai tidak hadir pukul 08:10 di hari tanpa jam khusus', function () {
    Carbon::setTestNow('2026-10-01 08:10:00');

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->whereDate('tanggal', '2026-10-01')->count())->toBe(0);
});

it('memakai jam tutup tanggal masing-masing saat mengisi hari yang terlewat', function () {
    $guruBaru = fn (string $dibuat): User => tap(buatGuru()->user, fn (User $user) => $user->forceFill(['created_at' => $dibuat])->save());
    // Dibuat pukul 08:30: setelah jam tutup khusus Jumat (08:00), tetapi sebelum jam tutup bawaan Kamis (09:00).
    $kamis = $guruBaru('2026-10-01 08:30:00');
    $jumat = $guruBaru('2026-10-02 08:30:00');
    Carbon::setTestNow('2026-10-05 09:10:00');

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    $tanggal = fn (User $user): array => Absensi::query()->where('user_id', $user->id)->orderBy('tanggal')->get()
        ->map(fn (Absensi $absensi): string => $absensi->tanggal->toDateString())->all();
    expect($tanggal($kamis))->toBe(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-05'])
        ->and($tanggal($jumat))->toBe(['2026-10-03', '2026-10-05']);
});

it('menghitung tidak absen pulang setelah jam pulang khusus hari itu tutup', function (string $tanggal, string $sekarang, int $jumlah) {
    Absensi::factory()->for($this->guru)->create(['tanggal' => $tanggal, 'waktu' => "{$tanggal} 06:50:00"]);
    Carbon::setTestNow($sekarang);

    $rekap = collect($this->actingAs(buatKepalaSekolah())->getJson('/api/v1/absensi/rekap?bulan=2026-10')->assertOk()->json('data'))
        ->keyBy('user.id');

    expect($rekap[$this->guru->id]['tidak_absen_pulang'])->toBe($jumlah);
})->with([
    'Jumat 12:00, jam pulang khusus tutup 11:30' => ['2026-10-02', '2026-10-02 12:00:00', 1],
    'Kamis 12:00, jam pulang bawaan tutup 15:00' => ['2026-10-01', '2026-10-01 12:00:00', 0],
]);
