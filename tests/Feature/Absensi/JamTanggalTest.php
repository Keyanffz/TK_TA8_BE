<?php

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Services\PengaturanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * 3–9 Oktober 2026 punya jam per tanggal "Pekan ujian": masuk 07:30–09:30 (terlambat setelah 08:00), pulang
 * 12:00–13:00. Jumat juga punya jam khusus (masuk 06:30–08:00, terlambat setelah 07:00). Hari lain memakai jam
 * bawaan: masuk 06:30–09:00 (terlambat setelah 07:15), pulang 11:00–15:00. Hari kerja Senin–Sabtu.
 */
const PEKAN_UJIAN = [
    'mulai' => '2026-10-03',
    'selesai' => '2026-10-09',
    'keterangan' => 'Pekan ujian',
    'jam_masuk' => ['buka' => '07:30', 'batas_terlambat' => '08:00', 'tutup' => '09:30'],
    'jam_pulang' => ['buka' => '12:00', 'tutup' => '13:00'],
];

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 06:00:00');
    Storage::fake('local');
    aturAbsensi([
        'lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH],
        'tanggal_mulai' => '2026-09-01',
        'jam_khusus' => [[
            'hari' => 5,
            'jam_masuk' => ['buka' => '06:30', 'batas_terlambat' => '07:00', 'tutup' => '08:00'],
            'jam_pulang' => ['buka' => '10:00', 'tutup' => '11:30'],
        ]],
        'jam_tanggal' => [PEKAN_UJIAN],
    ]);
    $this->guru = buatGuru()->user;
});

it('mendahulukan jam per tanggal, lalu jam khusus hari, lalu jam bawaan', function (string $waktu, string $status) {
    Carbon::setTestNow($waktu);

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertCreated()
        ->assertJsonPath('data.status', $status);
})->with([
    'Jumat 9 Oktober, di dalam pekan ujian' => ['2026-10-09 07:45:00', 'hadir'],
    'Jumat 2 Oktober, jam khusus Jumat' => ['2026-10-02 07:45:00', 'terlambat'],
    'Kamis 1 Oktober, jam bawaan' => ['2026-10-01 07:45:00', 'terlambat'],
]);

it('menampilkan jam per tanggal di status hari ini', function () {
    Carbon::setTestNow('2026-10-06 07:00:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertOk()
        ->assertJsonPath('data.masuk', ['buka' => '07:30', 'batas_terlambat' => '08:00', 'tutup' => '09:30', 'terbuka' => false, 'absensi' => null])
        ->assertJsonPath('data.pulang.buka', '12:00')
        ->assertJsonPath('data.pulang.tutup', '13:00');
});

it('tetap menolak absen di hari yang bukan hari kerja walau termasuk rentang jam per tanggal', function () {
    Carbon::setTestNow('2026-10-04 08:00:00');

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Hari Minggu bukan hari kerja, jadi tidak ada absensi.');
});

it('memakai jam pulang per tanggal untuk pulang lebih awal', function () {
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-10-06', 'waktu' => '2026-10-06 07:40:00']);
    Carbon::setTestNow('2026-10-06 11:30:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')->assertJsonPath('data.pulang.boleh_lebih_awal', true);
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Absen pulang baru bisa mulai pukul 12:00. Kalau perlu pulang lebih awal, tulis alasannya di keterangan.');
});

it('menandai tidak hadir setelah jam tutup per tanggal, bukan jam tutup bawaan', function () {
    Carbon::setTestNow('2026-10-06 09:10:00');
    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();
    expect(Absensi::query()->whereDate('tanggal', '2026-10-06')->count())->toBe(0);

    Carbon::setTestNow('2026-10-06 09:40:00');
    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();
    expect(Absensi::query()->whereDate('tanggal', '2026-10-06')->sole()->status)->toBe(StatusAbsensi::TidakHadir);
});

it('kembali ke jam bawaan sehari setelah rentang selesai', function () {
    Carbon::setTestNow('2026-10-10 09:10:00');

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->whereDate('tanggal', '2026-10-10')->sole()->status)->toBe(StatusAbsensi::TidakHadir);
});

it('menyimpan jam per tanggal urut tanggal mulai', function () {
    $ramadan = [...PEKAN_UJIAN, 'mulai' => '2027-02-08', 'selesai' => '2027-03-09', 'keterangan' => '  Ramadan  '];

    $this->actingAs(buatKepalaSekolah())->putJson('/api/v1/pengaturan', ['items' => ['absensi.jam_tanggal' => [$ramadan, PEKAN_UJIAN]]])
        ->assertOk();

    expect(app(PengaturanService::class)->nilai('absensi.jam_tanggal'))
        ->toBe([PEKAN_UJIAN, [...$ramadan, 'keterangan' => 'Ramadan']]);
});

it('memvalidasi jam per tanggal', function (array $rentang, string $field) {
    $this->actingAs(buatKepalaSekolah())->putJson('/api/v1/pengaturan', ['items' => ['absensi.jam_tanggal' => $rentang]])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'rentang bertumpuk' => [[PEKAN_UJIAN, [...PEKAN_UJIAN, 'mulai' => '2026-10-09', 'selesai' => '2026-10-12', 'keterangan' => 'Pentas seni']], 'absensi.jam_tanggal.1.mulai'],
    'selesai sebelum mulai' => [[[...PEKAN_UJIAN, 'selesai' => '2026-10-01']], 'absensi.jam_tanggal.0.selesai'],
    'jam masuk tidak berurutan' => [[[...PEKAN_UJIAN, 'jam_masuk' => ['buka' => '07:30', 'batas_terlambat' => '10:00', 'tutup' => '09:30']]], 'absensi.jam_tanggal.0.jam_masuk'],
    'jam pulang tutup sebelum buka' => [[[...PEKAN_UJIAN, 'jam_pulang' => ['buka' => '12:00', 'tutup' => '11:00']]], 'absensi.jam_tanggal.0.jam_pulang'],
    'tanpa keterangan' => [[[...PEKAN_UJIAN, 'keterangan' => '']], 'absensi.jam_tanggal.0.keterangan'],
    'tanggal bukan YYYY-MM-DD' => [[[...PEKAN_UJIAN, 'mulai' => '03/10/2026']], 'absensi.jam_tanggal.0.mulai'],
]);
