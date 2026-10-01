<?php

use App\Enums\StatusAbsensi;
use App\Enums\StatusAkun;
use App\Models\Absensi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Jumat, 2 Oktober 2026 pukul 10:00. Bu Nur: 1 Oktober hadir dengan foto dan sudah pulang, 2 Oktober
 * terlambat dan belum pulang, 30 September ditandai tidak hadir.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    Storage::fake('local');
    Storage::disk('local')->put('absensi/nur.jpg', 'isi-foto');
    aturAbsensi(['tanggal_mulai' => '2026-09-01']);
    $this->kepsek = buatKepalaSekolah();
    $this->nur = buatGuru(atributGuru: ['jabatan' => 'Guru Kelas'])->user;
    $this->nur->update(['name' => 'Nur Aini, S.Pd.']);
    $this->dwi = buatGuru()->user;

    $this->masukKamis = Absensi::factory()->for($this->nur)->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:55:00', 'foto_path' => 'absensi/nur.jpg']);
    Absensi::factory()->for($this->nur)->pulang()->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 12:10:00']);
    Absensi::factory()->for($this->nur)->create(['tanggal' => '2026-10-02', 'waktu' => '2026-10-02 07:40:00', 'status' => StatusAbsensi::Terlambat]);
    $this->tidakHadir = Absensi::factory()->for($this->nur)->tidakHadir()->create(['tanggal' => '2026-09-30']);
});

it('melayani foto absensi untuk pemiliknya dan Kepala Sekolah', function (Closure $siapa) {
    $respons = $this->actingAs($siapa($this))->get("/api/v1/absensi/{$this->masukKamis->id}/foto")
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=1800, private');

    expect($respons->streamedContent())->toBe('isi-foto');
})->with([
    'pemilik' => [fn ($test) => $test->nur],
    'Kepala Sekolah' => [fn ($test) => $test->kepsek],
]);

it('menolak guru lain membuka foto absensi dengan 404', function () {
    $this->actingAs($this->dwi)->getJson("/api/v1/absensi/{$this->masukKamis->id}/foto")
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('menolak wali murid dan pengunjung tanpa login membuka foto absensi', function () {
    $this->getJson("/api/v1/absensi/{$this->masukKamis->id}/foto")->assertUnauthorized();

    $this->actingAs(User::factory()->waliMurid()->create())->getJson("/api/v1/absensi/{$this->masukKamis->id}/foto")
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('membalas 404 untuk absensi tanpa foto', function () {
    $this->actingAs($this->kepsek)->getJson("/api/v1/absensi/{$this->tidakHadir->id}/foto")->assertNotFound();
});

it('tidak menyimpan foto absensi di disk publik', function () {
    Storage::fake('public');
    Carbon::setTestNow('2026-10-03 06:50:00');
    aturAbsensi(['lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH]]);

    $this->actingAs($this->dwi)->post('/api/v1/absensi', isianAbsen())->assertCreated();

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('mencatat koreksi status oleh Kepala Sekolah beserta catatan, pengoreksi, dan waktunya', function () {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$this->tidakHadir->id}/koreksi", [
        'status' => 'hadir', 'catatan' => 'Mengikuti pelatihan di dinas, surat tugas ada.',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Status absensi dikoreksi menjadi Hadir.')
        ->assertJsonPath('data.status', 'hadir')
        ->assertJsonPath('data.catatan_koreksi', 'Mengikuti pelatihan di dinas, surat tugas ada.')
        ->assertJsonPath('data.dikoreksi_oleh', ['id' => $this->kepsek->id, 'nama' => $this->kepsek->name])
        ->assertJsonPath('data.dikoreksi_at', '2026-10-02T10:00:00+07:00');

    $tersimpan = $this->tidakHadir->fresh();
    $log = Activity::query()->where('log_name', 'absensi')->sole();
    expect($tersimpan?->status)->toBe(StatusAbsensi::Hadir)
        ->and($tersimpan?->dikoreksi_oleh)->toBe($this->kepsek->id)
        ->and($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->event)->toBe('dikoreksi')
        ->and($log->properties->all())->toMatchArray(['user_id' => $this->nur->id, 'tanggal' => '2026-09-30', 'sebelum' => 'tidak_hadir', 'sesudah' => 'hadir']);
});

it('menolak koreksi oleh guru, termasuk untuk absensinya sendiri', function () {
    $this->actingAs($this->nur)->patchJson("/api/v1/absensi/{$this->tidakHadir->id}/koreksi", ['status' => 'hadir', 'catatan' => 'Saya hadir.'])
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($this->tidakHadir->fresh()?->status)->toBe(StatusAbsensi::TidakHadir)
        ->and(Activity::query()->where('log_name', 'absensi')->count())->toBe(0);
});

it('mewajibkan catatan dan status yang dikenal saat koreksi', function (array $isian, string $field) {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$this->tidakHadir->id}/koreksi", $isian)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors([$field]);

    expect($this->tidakHadir->fresh()?->status)->toBe(StatusAbsensi::TidakHadir);
})->with([
    'tanpa catatan' => [['status' => 'hadir'], 'catatan'],
    'catatan kosong' => [['status' => 'hadir', 'catatan' => '   '], 'catatan'],
    'status asing' => [['status' => 'izin', 'catatan' => 'Izin keluarga.'], 'status'],
]);

it('menolak koreksi absen pulang dan koreksi ke status yang sama', function () {
    $pulang = Absensi::query()->where('jenis', 'pulang')->sole();

    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$pulang->id}/koreksi", ['status' => 'hadir', 'catatan' => 'Salah pilih.'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$this->tidakHadir->id}/koreksi", ['status' => 'tidak_hadir', 'catatan' => 'Tetap.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Status absensi ini sudah Tidak Hadir.');
});

it('mengizinkan absen pulang setelah status tidak hadir dikoreksi menjadi hadir', function () {
    aturAbsensi(['lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH]]);
    $pagi = Absensi::factory()->for($this->dwi)->tidakHadir()->create(['tanggal' => '2026-10-02']);
    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$pagi->id}/koreksi", ['status' => 'hadir', 'catatan' => 'Sinyal GPS hilang, hadir sejak pagi.'])->assertOk();
    Carbon::setTestNow('2026-10-02 12:00:00');

    $this->actingAs($this->dwi)->post('/api/v1/absensi', isianAbsen('pulang'))->assertCreated();
});

it('menampilkan riwayat pribadi guru per bulan, terbaru dulu', function () {
    Absensi::factory()->for($this->dwi)->create(['tanggal' => '2026-10-01']);

    $this->actingAs($this->nur)->getJson('/api/v1/absensi?bulan=2026-10')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.tanggal', '2026-10-02')
        ->assertJsonPath('data.0.status', 'terlambat')
        ->assertJsonPath('data.1.tanggal', '2026-10-01')
        ->assertJsonPath('data.1.jenis', 'masuk')
        ->assertJsonPath('data.1.ada_foto', true)
        ->assertJsonPath('data.2.jenis', 'pulang');

    $this->actingAs($this->nur)->getJson('/api/v1/absensi?bulan=2026-09')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'tidak_hadir');
    $this->actingAs($this->nur)->getJson('/api/v1/absensi')->assertJsonCount(3, 'data');
});

it('menolak guru membuka riwayat peserta lain, dan mengizinkan Kepala Sekolah', function () {
    $this->actingAs($this->dwi)->getJson("/api/v1/absensi?bulan=2026-10&user_id={$this->nur->id}")
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->actingAs($this->kepsek)->getJson("/api/v1/absensi?bulan=2026-10&user_id={$this->nur->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data');
    $this->actingAs($this->kepsek)->getJson('/api/v1/absensi?bulan=2026-10')->assertOk()->assertJsonCount(0, 'data');
});

it('merekap absensi per bulan per peserta', function () {
    $nonaktif = buatGuru(StatusAkun::Nonaktif)->user;

    $rekap = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/rekap?bulan=2026-10')->assertOk()->json('data'))->keyBy('user.id');

    expect($rekap)->toHaveCount(3)
        ->and($rekap[$this->nur->id])->toBe([
            'user' => ['id' => $this->nur->id, 'nama' => 'Nur Aini, S.Pd.', 'jabatan' => 'Guru Kelas'],
            'hadir' => 1, 'terlambat' => 1, 'tidak_hadir' => 0, 'tidak_absen_pulang' => 0,
        ])
        ->and($rekap[$this->dwi->id])->toMatchArray(['hadir' => 0, 'terlambat' => 0, 'tidak_hadir' => 0, 'tidak_absen_pulang' => 0])
        ->and($rekap[$this->kepsek->id]['user']['jabatan'])->toBe('Kepala Sekolah')
        ->and($rekap->has($nonaktif->id))->toBeFalse();

    $september = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/rekap?bulan=2026-09')->json('data'))->keyBy('user.id');
    expect($september[$this->nur->id])->toMatchArray(['hadir' => 0, 'tidak_hadir' => 1]);
});

it('menghitung tidak absen pulang setelah jam pulang tutup', function () {
    Carbon::setTestNow('2026-10-02 15:01:00');
    $rekap = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/rekap?bulan=2026-10')->json('data'))->keyBy('user.id');
    expect($rekap[$this->nur->id]['tidak_absen_pulang'])->toBe(1);

    Carbon::setTestNow('2026-10-03 06:00:00');
    $rekap = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/rekap?bulan=2026-10')->json('data'))->keyBy('user.id');
    expect($rekap[$this->nur->id]['tidak_absen_pulang'])->toBe(1);
});

it('tetap merekap guru nonaktif yang punya absensi di bulan itu', function () {
    $this->nur->update(['status' => StatusAkun::Nonaktif]);

    $rekap = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/rekap?bulan=2026-10')->json('data'))->keyBy('user.id');

    expect($rekap[$this->nur->id]['hadir'])->toBe(1);
});

it('mengekspor rekap sebagai CSV', function () {
    $respons = $this->actingAs($this->kepsek)->get('/api/v1/absensi/rekap/export?bulan=2026-10')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="rekap-absensi-2026-10.csv"');

    $baris = explode("\n", trim($respons->getContent()));
    expect($baris[0])->toBe("\xEF\xBB\xBFNama,Jabatan,Hadir,Terlambat,\"Tidak Hadir\",\"Tidak Absen Pulang\"")
        ->and($baris)->toHaveCount(4)
        ->and($baris)->toContain('"Nur Aini, S.Pd.","Guru Kelas",1,1,0,0');
});

it('menolak guru membuka rekap dan ekspornya', function () {
    $this->actingAs($this->nur)->getJson('/api/v1/absensi/rekap')->assertForbidden();
    $this->actingAs($this->nur)->getJson('/api/v1/absensi/rekap/export')->assertForbidden();
});

it('menolak bulan yang formatnya salah', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/absensi/rekap?bulan=10-2026')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bulan']);
});
