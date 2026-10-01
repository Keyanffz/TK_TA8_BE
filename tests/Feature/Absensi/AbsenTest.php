<?php

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Enums\StatusAkun;
use App\Models\Absensi;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Kamis, 1 Oktober 2026 pukul 06:50. Jam masuk 06:30–09:00 (terlambat setelah 07:15), jam pulang 11:00–15:00,
 * hari kerja Senin–Sabtu, radius 100 m, batas akurasi 100 m, absensi berlaku sejak 1 September 2026.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-01 06:50:00');
    Storage::fake('local');
    aturAbsensi(['lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH], 'tanggal_mulai' => '2026-09-01']);
    $this->guru = buatGuru()->user;
});

it('mencatat absen masuk guru dengan lokasi, akurasi, jarak, waktu server, dan foto', function () {
    $data = $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertCreated()
        ->assertJsonPath('message', 'Absen masuk tercatat pukul 06:50.')
        ->json('data');

    $absensi = Absensi::query()->sole();
    expect($data)->toMatchArray([
        'user_id' => $this->guru->id, 'tanggal' => '2026-10-01', 'jenis' => 'masuk', 'status' => 'hadir',
        'waktu' => '2026-10-01T06:50:00+07:00', 'akurasi_meter' => 15, 'ada_foto' => true, 'dikoreksi_oleh' => null,
    ])
        ->and($data['jarak_meter'])->toBeBetween(43, 46)
        ->and($absensi->latitude)->toEqualWithDelta(-6.9899, 0.0000001)
        ->and($absensi->longitude)->toBe(LONGITUDE_SEKOLAH)
        ->and($absensi->foto_path)->toStartWith('absensi/');
    Storage::disk('local')->assertExists($absensi->foto_path);
});

it('memakai jam server, bukan waktu dari perangkat', function () {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen(ubah: ['waktu' => '2026-10-01T06:00:00+07:00', 'tanggal' => '2026-09-30']))
        ->assertCreated()
        ->assertJsonPath('data.waktu', '2026-10-01T06:50:00+07:00')
        ->assertJsonPath('data.tanggal', '2026-10-01');
});

it('menentukan hadir atau terlambat dari batas terlambat', function (string $jam, string $status) {
    Carbon::setTestNow("2026-10-01 {$jam}");

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertCreated()
        ->assertJsonPath('data.status', $status);
})->with([
    'saat jam buka' => ['06:30:00', 'hadir'],
    'tepat di batas terlambat' => ['07:15:40', 'hadir'],
    'semenit setelah batas' => ['07:16:00', 'terlambat'],
    'menjelang jam tutup' => ['09:00:30', 'terlambat'],
]);

it('menerima absen dari Kepala Sekolah', function () {
    $kepsek = buatKepalaSekolah();

    $this->actingAs($kepsek)->post('/api/v1/absensi', isianAbsen())->assertCreated()->assertJsonPath('data.user_id', $kepsek->id);
});

it('menolak absen yang melanggar aturan tanpa menyimpan data atau foto', function (Closure $siapkan, array $ubah, string $pesan) {
    $siapkan();

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen(ubah: $ubah))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', $pesan);

    expect(Absensi::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'di luar radius' => [fn () => null, ['latitude' => LATITUDE_SEKOLAH + 0.002], 'Anda berada 223 m dari sekolah, di luar radius absen 100 m. Absen dari area sekolah.'],
    'akurasi jelek' => [fn () => null, ['akurasi' => 150.4], 'Akurasi lokasi 151 m, melebihi batas 100 m. Nyalakan GPS, pindah ke tempat terbuka, lalu coba lagi.'],
    'sebelum jam buka' => [fn () => Carbon::setTestNow('2026-10-01 06:29:59'), [], 'Absen masuk hanya bisa pukul 06:30–09:00. Sekarang pukul 06:29.'],
    'setelah jam tutup' => [fn () => Carbon::setTestNow('2026-10-01 09:01:00'), [], 'Absen masuk hanya bisa pukul 06:30–09:00. Sekarang pukul 09:01.'],
    'tanggal libur' => [fn () => aturAbsensi(['tanggal_libur' => ['2026-10-01']]), [], 'Hari ini libur sekolah, jadi tidak ada absensi.'],
    'bukan hari kerja' => [fn () => Carbon::setTestNow('2026-10-04 06:50:00'), [], 'Hari Minggu bukan hari kerja, jadi tidak ada absensi.'],
    'pulang tanpa masuk' => [fn () => Carbon::setTestNow('2026-10-01 12:00:00'), ['jenis' => 'pulang'], 'Absen pulang hanya bisa setelah absen masuk, dan hari ini Anda belum absen masuk.'],
    'sebelum tanggal mulai absensi' => [fn () => aturAbsensi(['tanggal_mulai' => '2026-10-05']), [], 'Absensi baru berlaku mulai 5 Oktober 2026.'],
    'lokasi sekolah belum diatur' => [fn () => aturAbsensi(['lokasi' => null]), [], 'Lokasi sekolah belum diatur. Minta Kepala Sekolah mengisi pengaturan absensi.'],
]);

it('menerima absen tepat di tepi radius yang diatur Kepala Sekolah', function () {
    aturAbsensi(['radius_meter' => 250]);

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen(ubah: ['latitude' => LATITUDE_SEKOLAH + 0.002]))->assertCreated();
});

it('menolak absen jenis yang sama dua kali di hari yang sama', function () {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())->assertCreated();

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Anda sudah absen masuk hari ini pukul 06:50.');

    expect(Absensi::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('menjaga satu absensi per peserta, tanggal, dan jenis dengan unique index', function () {
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-10-01']);

    expect(fn () => Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-10-01']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('mencatat absen pulang setelah absen masuk, tanpa status', function () {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())->assertCreated();
    Carbon::setTestNow('2026-10-01 12:05:00');

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertCreated()
        ->assertJsonPath('data.jenis', 'pulang')
        ->assertJsonPath('data.status', null)
        ->assertJsonPath('message', 'Absen pulang tercatat pukul 12:05.');

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Anda sudah absen pulang hari ini pukul 12:05.');
});

it('menolak absen pulang kalau absen masuk hari itu tercatat tidak hadir', function () {
    Absensi::factory()->for($this->guru)->tidakHadir()->create(['tanggal' => '2026-10-01']);
    Carbon::setTestNow('2026-10-01 12:05:00');

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Absen pulang hanya bisa setelah absen masuk, dan hari ini Anda belum absen masuk.');
});

it('memvalidasi isian dan foto', function (array $ubah, string $field) {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen(ubah: $ubah), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors([$field]);

    expect(Absensi::query()->count())->toBe(0);
})->with([
    'jenis asing' => [['jenis' => 'istirahat'], 'jenis'],
    'tanpa latitude' => [['latitude' => null], 'latitude'],
    'longitude di luar rentang' => [['longitude' => 190], 'longitude'],
    'akurasi nol' => [['akurasi' => 0], 'akurasi'],
    'tanpa foto' => [['foto' => null], 'foto'],
    'foto lebih dari 2 MB' => [['foto' => UploadedFile::fake()->image('swafoto.jpg')->size(2049)], 'foto'],
    'foto bukan jpeg, png, atau webp' => [['foto' => UploadedFile::fake()->image('swafoto.gif')], 'foto'],
    'foto berupa PDF' => [['foto' => UploadedFile::fake()->create('swafoto.pdf', 100, 'application/pdf')], 'foto'],
]);

it('menerima foto png dan webp', function (string $nama) {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen(ubah: ['foto' => UploadedFile::fake()->image($nama, 320, 240)]))
        ->assertCreated();
})->with(['swafoto.png', 'swafoto.webp']);

it('membatasi percobaan absen 10 kali per menit per pengguna', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen());
    }

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');

    $this->actingAs(buatGuru()->user)->post('/api/v1/absensi', isianAbsen())->assertCreated();
});

it('menolak guru nonaktif', function () {
    $nonaktif = buatGuru(StatusAkun::Nonaktif)->user;

    $this->actingAs($nonaktif)->post('/api/v1/absensi', isianAbsen())
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    $this->actingAs($nonaktif)->getJson('/api/v1/absensi/hari-ini')->assertForbidden();
});

it('menampilkan status hari ini: jam, jendela yang terbuka, dan absensi yang sudah tercatat', function () {
    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Berhasil', 'meta' => null, 'data' => [
            'tanggal' => '2026-10-01',
            'waktu_server' => '2026-10-01T06:50:00+07:00',
            'hari_kerja' => true,
            'tanggal_libur' => false,
            'tanggal_mulai' => '2026-09-01',
            'lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH],
            'radius_meter' => 100,
            'batas_akurasi_meter' => 100,
            'masuk' => ['buka' => '06:30', 'batas_terlambat' => '07:15', 'tutup' => '09:00', 'terbuka' => true, 'absensi' => null],
            'pulang' => ['buka' => '11:00', 'tutup' => '15:00', 'terbuka' => false, 'absensi' => null],
        ]]);

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())->assertCreated();
    Carbon::setTestNow('2026-10-01 11:30:00');

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertOk()
        ->assertJsonPath('data.masuk.terbuka', false)
        ->assertJsonPath('data.masuk.absensi.status', 'hadir')
        ->assertJsonPath('data.masuk.absensi.jenis', JenisAbsensi::Masuk->value)
        ->assertJsonPath('data.pulang.terbuka', true)
        ->assertJsonPath('data.pulang.absensi', null);
});

it('menandai hari libur dan bukan hari kerja di status hari ini', function () {
    aturAbsensi(['tanggal_libur' => ['2026-10-01']]);
    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertJsonPath('data.hari_kerja', false)
        ->assertJsonPath('data.tanggal_libur', true)
        ->assertJsonPath('data.masuk.terbuka', false);

    Carbon::setTestNow('2026-10-04 06:50:00');
    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertJsonPath('data.hari_kerja', false)
        ->assertJsonPath('data.tanggal_libur', false)
        ->assertJsonPath('data.masuk.terbuka', false);
});

it('menerima absen tepat pada tanggal mulai absensi', function () {
    aturAbsensi(['tanggal_mulai' => '2026-10-01']);

    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())->assertCreated();
});

it('menutup jam absen di status hari ini sebelum tanggal mulai absensi', function () {
    aturAbsensi(['tanggal_mulai' => '2026-10-05']);

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')
        ->assertJsonPath('data.tanggal_mulai', '2026-10-05')
        ->assertJsonPath('data.hari_kerja', true)
        ->assertJsonPath('data.masuk.terbuka', false)
        ->assertJsonPath('data.pulang.terbuka', false);
});

it('tidak menampilkan absensi peserta lain di status hari ini', function () {
    Absensi::factory()->for(buatGuru()->user)->create(['tanggal' => '2026-10-01', 'status' => StatusAbsensi::Terlambat]);

    $this->actingAs($this->guru)->getJson('/api/v1/absensi/hari-ini')->assertJsonPath('data.masuk.absensi', null);
});

it('menolak wali murid di semua endpoint absensi', function (string $metode, string $url) {
    $absensi = Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-10-01']);
    $wali = User::factory()->waliMurid()->create();

    $this->actingAs($wali)->json($metode, str_replace('{id}', (string) $absensi->id, $url))
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'status hari ini' => ['GET', '/api/v1/absensi/hari-ini'],
    'absen' => ['POST', '/api/v1/absensi'],
    'riwayat' => ['GET', '/api/v1/absensi'],
    'foto' => ['GET', '/api/v1/absensi/{id}/foto'],
    'koreksi' => ['PATCH', '/api/v1/absensi/{id}/koreksi'],
    'rekap' => ['GET', '/api/v1/absensi/rekap'],
    'export rekap' => ['GET', '/api/v1/absensi/rekap/export'],
    'pengaturan absensi' => ['GET', '/api/v1/pengaturan?grup=absensi'],
]);

it('menolak permintaan tanpa login di endpoint absensi', function () {
    $this->getJson('/api/v1/absensi/hari-ini')->assertUnauthorized();
    $this->postJson('/api/v1/absensi')->assertUnauthorized();
});
