<?php

use App\Models\Absensi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Kamis, 1 Oktober 2026. Jam masuk 06:30–09:00 (terlambat setelah 07:15), jam pulang 11:00–15:00. Bu Rina sudah
 * ditandai tidak hadir pada Rabu, 30 September.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-01 07:20:00');
    Storage::fake('local');
    aturAbsensi(['lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH], 'tanggal_mulai' => '2026-09-01']);
    $this->kepsek = buatKepalaSekolah();
    $this->guru = buatGuru()->user;
    $this->tidakHadir = Absensi::factory()->for($this->guru)->tidakHadir()->create(['tanggal' => '2026-09-30']);
});

/**
 * @return array{jenis: string, judul: string, pesan: string, url: string}
 */
function notifikasiSatuSatunya(User $user): array
{
    return $user->notifications()->sole()->data;
}

it('memberi tahu peserta setelah absen sendiri berhasil', function () {
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen())->assertCreated();

    $this->actingAs($this->guru)->getJson('/api/v1/notifikasi')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.jenis', 'absensi_tercatat')
        ->assertJsonPath('data.0.judul', 'Absen masuk tercatat')
        ->assertJsonPath('data.0.pesan', 'Absen masuk Kamis, 1 Oktober 2026 pukul 07:20 tercatat dengan status Terlambat.')
        ->assertJsonPath('data.0.url', '/mudarris/absensi/riwayat')
        ->assertJsonPath('data.0.dibaca_at', null);
});

it('menyebut jenis, status, jam, dan keterangan di notifikasi absen', function (Closure $absen, string $judul, string $pesan) {
    $absen($this);

    expect(notifikasiSatuSatunya($this->guru))->toMatchArray(['jenis' => 'absensi_tercatat', 'judul' => $judul, 'pesan' => $pesan]);
})->with([
    'izin' => [fn ($test) => $test->actingAs($test->guru)->post('/api/v1/absensi', ['jenis' => 'masuk', 'status' => 'izin', 'keterangan' => 'Mengantar ibu kontrol ke rumah sakit.', 'foto' => isianAbsen()['foto']])->assertCreated(),
        'Izin tercatat', 'Izin Kamis, 1 Oktober 2026 pukul 07:20 tercatat. Keterangan: Mengantar ibu kontrol ke rumah sakit.'],
    'pulang lebih awal' => [function ($test) {
        Absensi::factory()->for($test->guru)->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:55:00']);
        Carbon::setTestNow('2026-10-01 10:05:00');
        $test->actingAs($test->guru)->post('/api/v1/absensi', isianAbsen('pulang', ['keterangan' => 'Menjemput anak yang demam']))->assertCreated();
    }, 'Absen pulang lebih awal tercatat', 'Absen pulang lebih awal Kamis, 1 Oktober 2026 pukul 10:05 tercatat. Keterangan: Menjemput anak yang demam.'],
    'pulang' => [function ($test) {
        Absensi::factory()->for($test->guru)->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:55:00']);
        Carbon::setTestNow('2026-10-01 12:05:00');
        $test->actingAs($test->guru)->post('/api/v1/absensi', isianAbsen('pulang'))->assertCreated();
    }, 'Absen pulang tercatat', 'Absen pulang Kamis, 1 Oktober 2026 pukul 12:05 tercatat.'],
]);

it('memberi tahu Kepala Sekolah yang absen sendiri, dan tidak memberi tahu absen yang ditolak', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/absensi', isianAbsen())->assertCreated();
    $this->actingAs($this->guru)->post('/api/v1/absensi', isianAbsen(ubah: ['latitude' => LATITUDE_SEKOLAH + 0.002]))->assertStatus(422);

    expect(notifikasiSatuSatunya($this->kepsek)['jenis'])->toBe('absensi_tercatat')
        ->and($this->guru->notifications()->count())->toBe(0);
});

it('memberi tahu peserta saat Kepala Sekolah mengoreksi absen masuknya', function () {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$this->tidakHadir->id}/koreksi", ['status' => 'hadir', 'catatan' => 'Sinyal GPS hilang, hadir sejak pagi.'])->assertOk();

    expect(notifikasiSatuSatunya($this->guru))->toBe([
        'jenis' => 'absensi_dikoreksi',
        'judul' => 'Absensi dikoreksi Kepala Sekolah',
        'pesan' => 'Kepala Sekolah mengoreksi absen masuk Rabu, 30 September 2026 menjadi Hadir. Catatan: Sinyal GPS hilang, hadir sejak pagi.',
        'url' => '/mudarris/absensi/riwayat?bulan=2026-09',
    ])
        ->and($this->kepsek->notifications()->count())->toBe(0);
});

it('memberi tahu peserta saat Kepala Sekolah mencatat absensinya', function (array $isian, string $pesan) {
    Absensi::factory()->for($this->guru)->create(['tanggal' => '2026-09-29', 'waktu' => '2026-09-29 06:55:00']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', ['user_id' => $this->guru->id, 'tanggal' => '2026-09-29', ...$isian])->assertCreated();

    expect(notifikasiSatuSatunya($this->guru))->toMatchArray([
        'jenis' => 'absensi_dikoreksi',
        'judul' => 'Absensi dicatat Kepala Sekolah',
        'pesan' => $pesan,
        'url' => '/mudarris/absensi/riwayat?bulan=2026-09',
    ]);
})->with([
    'absen masuk' => [['tanggal' => '2026-09-28', 'status' => 'izin', 'catatan' => 'Izin lewat WhatsApp.'], 'Kepala Sekolah mencatat absen masuk Senin, 28 September 2026 dengan status Izin. Catatan: Izin lewat WhatsApp.'],
    'absen pulang' => [['jenis' => 'pulang', 'catatan' => 'Pulang bersama rombongan pukul 12.00'], 'Kepala Sekolah mencatat absen pulang Selasa, 29 September 2026. Catatan: Pulang bersama rombongan pukul 12.00.'],
]);

it('tidak memberi tahu Kepala Sekolah yang mengubah absensinya sendiri, atau koreksi yang ditolak', function () {
    $milikKepsek = Absensi::factory()->for($this->kepsek)->tidakHadir()->create(['tanggal' => '2026-09-30']);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$milikKepsek->id}/koreksi", ['status' => 'hadir', 'catatan' => 'Rapat di dinas.'])->assertOk();
    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', ['user_id' => $this->kepsek->id, 'tanggal' => '2026-09-29', 'status' => 'hadir', 'catatan' => 'Lupa absen.'])->assertCreated();
    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$this->tidakHadir->id}/koreksi", ['status' => 'tidak_hadir', 'catatan' => 'Tetap.'])->assertStatus(422);

    expect($this->kepsek->notifications()->count())->toBe(0)
        ->and($this->guru->notifications()->count())->toBe(0);
});
