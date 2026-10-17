<?php

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Kamis, 1 Oktober 2026 pukul 06:50. Jam masuk 06:30–09:00, jam pulang 11:00–15:00, hari kerja Senin–Sabtu,
 * absensi berlaku sejak 1 September 2026. Akun dibuat pagi itu, jadi scheduler tidak mengisi susulan.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-01 06:00:00');
    Storage::fake('local');
    aturAbsensi(['lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH], 'tanggal_mulai' => '2026-09-01']);
    $this->kepsek = buatKepalaSekolah();
    $this->guru = buatGuru(atributGuru: ['jabatan' => 'Guru Kelas'])->user;
    $this->guru->update(['name' => 'Rina Kusumawati']);
    Carbon::setTestNow('2026-10-01 06:50:00');
});

/**
 * Isian `POST /absensi` untuk izin atau sakit: tanpa lokasi.
 *
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function isianIzin(string $status = 'izin', array $ubah = []): array
{
    return [
        'jenis' => 'masuk',
        'status' => $status,
        'keterangan' => 'Mengantar anak imunisasi ke puskesmas.',
        'foto' => UploadedFile::fake()->image('surat.jpg', 640, 480),
        ...$ubah,
    ];
}

it('mencatat izin dan sakit tanpa lokasi, dengan keterangan, foto, dan jam server', function (string $status, string $pesan) {
    $data = $this->actingAs($this->guru)->post('/api/v1/absensi', isianIzin($status))
        ->assertCreated()
        ->assertJsonPath('message', $pesan)
        ->json('data');

    expect($data)->toMatchArray([
        'jenis' => 'masuk', 'status' => $status, 'waktu' => '2026-10-01T06:50:00+07:00',
        'latitude' => null, 'longitude' => null, 'akurasi_meter' => null, 'jarak_meter' => null,
        'ada_foto' => true, 'keterangan' => 'Mengantar anak imunisasi ke puskesmas.', 'pulang_awal' => false,
    ]);
    Storage::disk('local')->assertExists(Absensi::query()->sole()->foto_path);
})->with([
    'izin' => ['izin', 'Izin tercatat pukul 06:50.'],
    'sakit' => ['sakit', 'Sakit tercatat pukul 06:50.'],
]);

it('tidak memakai lokasi untuk izin walau dikirim atau lokasi sekolah belum diatur', function () {
    aturAbsensi(['lokasi' => null]);

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianIzin(ubah: ['latitude' => LATITUDE_SEKOLAH + 0.05, 'longitude' => LONGITUDE_SEKOLAH, 'akurasi' => 3000]))
        ->assertCreated()
        ->assertJsonPath('data.latitude', null)
        ->assertJsonPath('data.akurasi_meter', null)
        ->assertJsonPath('data.jarak_meter', null);
});

it('menyimpan keterangan opsional pada absen masuk biasa', function () {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen(ubah: ['keterangan' => 'Ban motor bocor di jalan.']))
        ->assertCreated()
        ->assertJsonPath('data.status', 'hadir')
        ->assertJsonPath('data.keterangan', 'Ban motor bocor di jalan.');
});

it('menolak izin dan sakit yang melanggar aturan absen masuk tanpa menyimpan data atau foto', function (Closure $siapkan, string $pesan) {
    $siapkan($this);
    $sebelum = Absensi::query()->count();

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianIzin('sakit'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', $pesan);

    expect(Absensi::query()->count())->toBe($sebelum)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'sebelum jam masuk buka' => [fn () => Carbon::setTestNow('2026-10-01 06:29:00'), 'Absen masuk hanya bisa pukul 06:30–09:00. Sekarang pukul 06:29.'],
    'setelah jam masuk tutup' => [fn () => Carbon::setTestNow('2026-10-01 09:01:00'), 'Absen masuk hanya bisa pukul 06:30–09:00. Sekarang pukul 09:01.'],
    'tanggal libur' => [fn () => aturAbsensi(['tanggal_libur' => ['2026-10-01']]), 'Hari ini libur sekolah, jadi tidak ada absensi.'],
    'bukan hari kerja' => [fn () => Carbon::setTestNow('2026-10-04 06:50:00'), 'Hari Minggu bukan hari kerja, jadi tidak ada absensi.'],
    'sebelum tanggal mulai' => [fn () => aturAbsensi(['tanggal_mulai' => '2026-10-05']), 'Absensi baru berlaku mulai 5 Oktober 2026.'],
    'sudah absen masuk' => [fn ($test) => Absensi::factory()->for($test->guru)->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:45:00']), 'Anda sudah absen masuk hari ini pukul 06:45.'],
    'sudah izin' => [fn ($test) => Absensi::factory()->for($test->guru)->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:40:00', 'status' => StatusAbsensi::Izin, 'latitude' => null, 'longitude' => null]), 'Anda sudah mencatat izin hari ini pukul 06:40.'],
    'izin dicatat Kepala Sekolah' => [fn ($test) => Absensi::factory()->for($test->guru)->tidakHadir()->create(['tanggal' => '2026-10-01', 'status' => StatusAbsensi::Izin]), 'Absen masuk hari ini sudah dicatat Kepala Sekolah dengan status Izin.'],
]);

it('menolak absen masuk biasa setelah peserta mencatat sakit', function () {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianIzin('sakit'))->assertCreated();

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Anda sudah mencatat sakit hari ini pukul 06:50.');
});

it('memvalidasi isian izin dan sakit', function (array $isian, string $field, ?string $pesan) {
    $respons = $this->actingAs($this->guru)->post('/api/v1/absensi', $isian, ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors([$field]);

    if ($pesan !== null) {
        $respons->assertJsonPath("errors.{$field}.0", $pesan);
    }
    expect(Absensi::query()->count())->toBe(0);
})->with([
    'izin tanpa keterangan' => [isianIzin(ubah: ['keterangan' => null]), 'keterangan', 'Tulis keterangan izin atau sakit.'],
    'sakit tanpa foto' => [isianIzin('sakit', ['foto' => null]), 'foto', 'Foto wajib diambil sebelum absen.'],
    'keterangan lebih dari 500 karakter' => [isianIzin(ubah: ['keterangan' => str_repeat('a', 501)]), 'keterangan', null],
    'status hadir dari peserta' => [isianIzin('hadir'), 'status', null],
    'status tidak hadir dari peserta' => [isianIzin('tidak_hadir'), 'status', null],
    'status untuk absen pulang' => [isianIzin(ubah: ['jenis' => 'pulang']), 'status', 'Absen pulang tidak punya status.'],
    'absen biasa tanpa lokasi' => [isianIzin(ubah: ['status' => null]), 'latitude', 'Lokasi belum terbaca. Izinkan akses lokasi lalu coba lagi.'],
]);

it('menolak absen pulang pada hari izin atau sakit', function (string $status, string $pesan) {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianIzin($status))->assertCreated();
    Carbon::setTestNow('2026-10-01 12:00:00');

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', $pesan);
})->with([
    'izin' => ['izin', 'Hari ini Anda tercatat izin, jadi tidak perlu absen pulang.'],
    'sakit' => ['sakit', 'Hari ini Anda tercatat sakit, jadi tidak perlu absen pulang.'],
]);

it('tidak menandai tidak hadir peserta yang mencatat izin', function () {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianIzin())->assertCreated();
    Carbon::setTestNow('2026-10-01 09:10:00');

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    expect(Absensi::query()->where('user_id', $this->guru->id)->sole()->status)->toBe(StatusAbsensi::Izin)
        ->and(Absensi::query()->where('user_id', $this->kepsek->id)->sole()->status)->toBe(StatusAbsensi::TidakHadir);
});

it('mengizinkan Kepala Sekolah mencatat dan mengoreksi absen masuk menjadi izin atau sakit', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', [
        'user_id' => $this->guru->id, 'tanggal' => '2026-09-30', 'status' => 'izin', 'catatan' => 'Izin lewat WhatsApp, menghadiri pernikahan saudara.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'izin')
        ->assertJsonPath('data.keterangan', null)
        ->assertJsonPath('message', 'Absen masuk dicatat dengan status Izin.');

    $tidakHadir = Absensi::factory()->for($this->guru)->tidakHadir()->create(['tanggal' => '2026-09-29']);
    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$tidakHadir->id}/koreksi", ['status' => 'sakit', 'catatan' => 'Surat dokter menyusul.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'sakit');
});

it('menolak Kepala Sekolah mencatat absen pulang pada hari izin', function () {
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-09-30', 'status' => StatusAbsensi::Izin, 'latitude' => null, 'longitude' => null]);

    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', [
        'user_id' => $this->guru->id, 'tanggal' => '2026-09-30', 'jenis' => 'pulang', 'catatan' => 'Lupa absen pulang.',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Absen masuk Rina Kusumawati tanggal 30 September 2026 tercatat izin, jadi tidak ada absen pulang.');
});

it('merekap izin dan sakit tanpa menghitungnya sebagai tidak absen pulang', function () {
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-09-28', 'status' => StatusAbsensi::Izin]);
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-09-29', 'status' => StatusAbsensi::Sakit]);
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-09-30', 'status' => StatusAbsensi::Sakit]);
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-09-25']);

    $rekap = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/rekap?bulan=2026-09')->assertOk()->json('data'))->keyBy('user.id');
    expect($rekap[$this->guru->id])->toMatchArray(['hadir' => 1, 'terlambat' => 0, 'izin' => 1, 'sakit' => 2, 'tidak_hadir' => 0, 'tidak_absen_pulang' => 1]);

    $csv = $this->actingAs($this->kepsek)->get('/api/v1/absensi/rekap/export?bulan=2026-09')->assertOk()->getContent();
    expect(explode("\n", trim($csv)))->toContain('"Rina Kusumawati","Guru Kelas",1,0,1,2,0,1');
});
