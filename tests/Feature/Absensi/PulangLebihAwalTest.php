<?php

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Kamis, 1 Oktober 2026. Jam masuk 06:30–09:00, jam pulang 11:00–15:00. Guru sudah absen masuk pukul 06:55,
 * dan sekarang pukul 10:00, sebelum jam pulang buka.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    Storage::fake('local');
    aturAbsensi(['lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH], 'tanggal_mulai' => '2026-09-01']);
    $this->guru = buatGuru()->user;
    $this->masuk = Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:55:00']);
});

const ALASAN_PULANG_AWAL = 'Menjemput anak yang demam dari sekolahnya.';

it('menerima pulang lebih awal dengan keterangan, lokasi, dan foto', function () {
    $data = $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang', ['keterangan' => ALASAN_PULANG_AWAL]))
        ->assertCreated()
        ->assertJsonPath('message', 'Absen pulang lebih awal tercatat pukul 10:00.')
        ->json('data');

    expect($data)->toMatchArray([
        'jenis' => 'pulang', 'status' => null, 'waktu' => '2026-10-01T10:00:00+07:00', 'akurasi_meter' => 15,
        'ada_foto' => true, 'keterangan' => ALASAN_PULANG_AWAL, 'pulang_awal' => true,
    ])
        ->and($data['jarak_meter'])->toBeBetween(43, 46);
});

it('menolak pulang sebelum jam pulang buka tanpa keterangan', function () {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Absen pulang baru bisa mulai pukul 11:00. Kalau perlu pulang lebih awal, tulis alasannya di keterangan.');

    expect(Absensi::query()->where('jenis', 'pulang')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('menolak pulang lebih awal yang melanggar aturan absen pulang', function (Closure $siapkan, array $ubah, string $pesan) {
    $siapkan($this);

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang', ['keterangan' => ALASAN_PULANG_AWAL, ...$ubah]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', $pesan);

    expect(Absensi::query()->where('jenis', 'pulang')->where('pulang_awal', true)->count())->toBe(0);
})->with([
    'belum absen masuk' => [fn ($test) => $test->masuk->delete(), [], 'Absen pulang hanya bisa setelah absen masuk, dan hari ini Anda belum absen masuk.'],
    'absen masuk tidak hadir' => [fn ($test) => $test->masuk->update(['status' => StatusAbsensi::TidakHadir, 'waktu' => null]), [], 'Anda tercatat tidak hadir hari ini. Hubungi Kepala Sekolah kalau perlu koreksi.'],
    'sedang izin' => [fn ($test) => $test->masuk->update(['status' => StatusAbsensi::Izin]), [], 'Hari ini Anda tercatat izin, jadi tidak perlu absen pulang.'],
    'sudah absen pulang' => [fn ($test) => Absensi::factory()->for($test->guru)->pulang()->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 09:30:00']), [], 'Anda sudah absen pulang hari ini pukul 09:30.'],
    'di luar radius' => [fn () => null, ['latitude' => LATITUDE_SEKOLAH + 0.002], 'Anda berada 223 m dari sekolah, di luar radius absen 100 m. Absen dari area sekolah.'],
    'akurasi jelek' => [fn () => null, ['akurasi' => 150], 'Akurasi lokasi 150 m, melebihi batas 100 m. Nyalakan GPS, pindah ke tempat terbuka, lalu coba lagi.'],
    'setelah jam pulang tutup' => [fn () => Carbon::setTestNow('2026-10-01 15:01:00'), [], 'Absen pulang hanya bisa pukul 11:00–15:00. Sekarang pukul 15:01.'],
    'tanggal libur' => [fn () => aturAbsensi(['tanggal_libur' => ['2026-10-01']]), [], 'Hari ini libur sekolah, jadi tidak ada absensi.'],
]);

it('membandingkan jam pulang buka per menit', function (string $jam, bool $pulangAwal) {
    Carbon::setTestNow("2026-10-01 {$jam}");

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang', ['keterangan' => ALASAN_PULANG_AWAL]))
        ->assertCreated()
        ->assertJsonPath('data.pulang_awal', $pulangAwal);
})->with([
    'semenit sebelum buka' => ['10:59:59', true],
    'tepat saat buka' => ['11:00:00', false],
]);

it('menerima absen pulang biasa tanpa keterangan dan tanpa tanda pulang lebih awal', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertCreated()
        ->assertJsonPath('message', 'Absen pulang tercatat pukul 12:00.')
        ->assertJsonPath('data.keterangan', null)
        ->assertJsonPath('data.pulang_awal', false);
});

it('menandai di status hari ini kapan pulang lebih awal bisa dilakukan', function (Closure $siapkan, bool $boleh) {
    $siapkan($this);

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertOk()
        ->assertJsonPath('data.pulang.terbuka', false)
        ->assertJsonPath('data.pulang.boleh_lebih_awal', $boleh);
})->with([
    'sudah absen masuk hadir' => [fn () => null, true],
    'absen masuk terlambat' => [fn ($test) => $test->masuk->update(['status' => StatusAbsensi::Terlambat]), true],
    'belum absen masuk' => [fn ($test) => $test->masuk->delete(), false],
    'tidak hadir' => [fn ($test) => $test->masuk->update(['status' => StatusAbsensi::TidakHadir]), false],
    'sakit' => [fn ($test) => $test->masuk->update(['status' => StatusAbsensi::Sakit]), false],
    'sudah absen pulang' => [fn ($test) => Absensi::factory()->for($test->guru)->pulang()->create(['tanggal' => '2026-10-01', 'pulang_awal' => true]), false],
    'tanggal libur' => [fn () => aturAbsensi(['tanggal_libur' => ['2026-10-01']]), false],
    'sebelum tanggal mulai' => [fn () => aturAbsensi(['tanggal_mulai' => '2026-10-05']), false],
]);

it('tidak lagi menawarkan pulang lebih awal setelah jam pulang buka', function () {
    Carbon::setTestNow('2026-10-01 11:00:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertJsonPath('data.pulang.terbuka', true)
        ->assertJsonPath('data.pulang.boleh_lebih_awal', false);
});
