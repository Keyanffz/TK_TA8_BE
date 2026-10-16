<?php

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Enums\StatusAkun;
use App\Models\Absensi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Jumat, 2 Oktober 2026 pukul 08:00, sebelum jam masuk tutup (09:00), jadi scheduler belum membuat baris tidak
 * hadir hari ini. Kamis 1 Oktober: Bu Nur hadir dengan foto dan sudah pulang, Pak Dwi tidak hadir, Kepala
 * Sekolah belum punya baris. Bu Fitri nonaktif. Semua akun dibuat 1 September, tanggal mulai absensi.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-01 06:00:00');
    Storage::fake('local');
    aturAbsensi(['tanggal_mulai' => '2026-09-01', 'lokasi' => ['latitude' => LATITUDE_SEKOLAH, 'longitude' => LONGITUDE_SEKOLAH]]);
    $this->kepsek = buatKepalaSekolah();
    $this->kepsek->update(['name' => 'Hj. Umi Kulsum, S.Pd.']);
    $this->nur = buatGuru(atributGuru: ['jabatan' => 'Guru Kelas'])->user;
    $this->nur->update(['name' => 'Nur Aini, S.Pd.']);
    $this->dwi = buatGuru(atributGuru: ['jabatan' => 'Guru Pendamping'])->user;
    $this->dwi->update(['name' => 'Dwi Prasetyo']);
    $this->fitri = buatGuru(StatusAkun::Nonaktif)->user;

    Carbon::setTestNow('2026-10-02 08:00:00');
    $this->masukNur = Absensi::factory()->for($this->nur)->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:55:00', 'foto_path' => 'absensi/nur.jpg']);
    Absensi::factory()->for($this->nur)->pulang()->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 12:10:00']);
    $this->tidakHadirDwi = Absensi::factory()->for($this->dwi)->tidakHadir()->create(['tanggal' => '2026-10-01']);
});

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function isianKoreksiBaru(User $peserta, array $ubah = []): array
{
    return [
        'user_id' => $peserta->id,
        'tanggal' => '2026-10-02',
        'status' => 'hadir',
        'catatan' => 'Lupa absen, hadir sejak 06:50 menurut buku tamu.',
        ...$ubah,
    ];
}

it('menampilkan absen masuk dan pulang semua peserta pada satu tanggal, urut nama', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/absensi/harian?tanggal=2026-10-01')
        ->assertOk()
        ->assertJsonPath('data.tanggal', '2026-10-01')
        ->assertJsonPath('data.hari_kerja', true)
        ->assertJsonPath('data.tanggal_libur', false)
        ->assertJsonPath('data.tanggal_mulai', '2026-09-01')
        ->assertJsonCount(3, 'data.peserta')
        ->assertJsonPath('data.peserta.0.user', ['id' => $this->dwi->id, 'nama' => 'Dwi Prasetyo', 'jabatan' => 'Guru Pendamping'])
        ->assertJsonPath('data.peserta.0.masuk.id', $this->tidakHadirDwi->id)
        ->assertJsonPath('data.peserta.0.masuk.status', 'tidak_hadir')
        ->assertJsonPath('data.peserta.0.pulang', null)
        ->assertJsonPath('data.peserta.1.user', ['id' => $this->kepsek->id, 'nama' => 'Hj. Umi Kulsum, S.Pd.', 'jabatan' => 'Kepala Sekolah'])
        ->assertJsonPath('data.peserta.1.masuk', null)
        ->assertJsonPath('data.peserta.1.pulang', null)
        ->assertJsonPath('data.peserta.2.user.nama', 'Nur Aini, S.Pd.')
        ->assertJsonPath('data.peserta.2.masuk.id', $this->masukNur->id)
        ->assertJsonPath('data.peserta.2.masuk.ada_foto', true)
        ->assertJsonPath('data.peserta.2.masuk.waktu', '2026-10-01T06:55:00+07:00')
        ->assertJsonPath('data.peserta.2.pulang.jenis', 'pulang')
        ->assertJsonPath('data.peserta.2.pulang.waktu', '2026-10-01T12:10:00+07:00');
});

it('memakai hari ini kalau tanggal tidak dikirim', function () {
    Absensi::factory()->for($this->nur)->create(['tanggal' => '2026-10-02', 'waktu' => '2026-10-02 07:20:00', 'status' => StatusAbsensi::Terlambat]);

    $this->actingAs($this->kepsek)->getJson('/api/v1/absensi/harian')
        ->assertOk()
        ->assertJsonPath('data.tanggal', '2026-10-02')
        ->assertJsonPath('data.peserta.0.masuk', null)
        ->assertJsonPath('data.peserta.2.masuk.status', 'terlambat');
});

it('ikut menampilkan akun nonaktif yang punya absensi di tanggal itu saja', function () {
    Absensi::factory()->for($this->fitri)->create(['tanggal' => '2026-10-01']);

    $tanggalSatu = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/harian?tanggal=2026-10-01')->json('data.peserta'))->pluck('user.id');
    $tanggalDua = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/harian?tanggal=2026-10-02')->json('data.peserta'))->pluck('user.id');

    expect($tanggalSatu)->toContain($this->fitri->id)->toHaveCount(4)
        ->and($tanggalDua)->not->toContain($this->fitri->id)->toHaveCount(3);
});

it('menandai hari bukan hari kerja dan tanggal libur', function (string $tanggal, bool $libur) {
    aturAbsensi(['tanggal_libur' => ['2026-09-30']]);

    $this->actingAs($this->kepsek)->getJson("/api/v1/absensi/harian?tanggal={$tanggal}")
        ->assertOk()
        ->assertJsonPath('data.hari_kerja', false)
        ->assertJsonPath('data.tanggal_libur', $libur)
        ->assertJsonCount(3, 'data.peserta');
})->with([
    'Minggu' => ['2026-09-27', false],
    'tanggal libur hari Rabu' => ['2026-09-30', true],
]);

it('tidak menambah query walau peserta dan absensinya bertambah', function () {
    $hitung = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->kepsek)->getJson('/api/v1/absensi/harian?tanggal=2026-10-01')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    // Laravel melewati eager load pengoreksi kalau tidak ada satu baris pun yang dikoreksi, jadi data awal ikut punya satu.
    $this->tidakHadirDwi->update(['status' => StatusAbsensi::Hadir, 'catatan_koreksi' => 'Rapat di dinas.', 'dikoreksi_oleh' => $this->kepsek->id, 'dikoreksi_at' => now()]);
    $hitung();
    $sedikit = $hitung();

    foreach (range(1, 5) as $urutan) {
        $guru = buatGuru()->user;
        Absensi::factory()->for($guru)->create(['tanggal' => '2026-10-01', 'dikoreksi_oleh' => $this->kepsek->id, 'dikoreksi_at' => now(), 'catatan_koreksi' => "Koreksi {$urutan}."]);
        Absensi::factory()->for($guru)->pulang()->create(['tanggal' => '2026-10-01']);
    }

    expect($hitung())->toBe($sedikit);
});

it('menolak tanggal setelah hari ini dan format tanggal yang salah', function (string $tanggal, string $pesan) {
    $this->actingAs($this->kepsek)->getJson("/api/v1/absensi/harian?tanggal={$tanggal}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.tanggal.0', $pesan);
})->with([
    'besok' => ['2026-10-03', 'Tanggal tidak boleh setelah hari ini.'],
    'format salah' => ['02-10-2026', 'Tanggal tidak cocok dengan format Y-m-d.'],
]);

it('hanya mengizinkan Kepala Sekolah membuka absensi harian dan mencatat absen masuk baru', function () {
    foreach ([$this->nur, User::factory()->waliMurid()->create()] as $bukanKepsek) {
        $this->actingAs($bukanKepsek)->getJson('/api/v1/absensi/harian')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->actingAs($bukanKepsek)->postJson('/api/v1/absensi/koreksi', isianKoreksiBaru($this->nur))->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/absensi/harian')->assertUnauthorized();
    $this->postJson('/api/v1/absensi/koreksi', isianKoreksiBaru($this->nur))->assertUnauthorized();

    expect(Absensi::query()->whereDate('tanggal', '2026-10-02')->count())->toBe(0);
});

it('mencatat absen masuk tanpa waktu, lokasi, dan foto untuk hari yang belum punya baris', function (string $tanggal, string $status) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', isianKoreksiBaru($this->kepsek, ['tanggal' => $tanggal, 'status' => $status]))
        ->assertCreated()
        ->assertJsonPath('message', 'Absen masuk dicatat dengan status '.StatusAbsensi::from($status)->label().'.')
        ->assertJsonPath('data.user_id', $this->kepsek->id)
        ->assertJsonPath('data.tanggal', $tanggal)
        ->assertJsonPath('data.jenis', 'masuk')
        ->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.waktu', null)
        ->assertJsonPath('data.latitude', null)
        ->assertJsonPath('data.longitude', null)
        ->assertJsonPath('data.akurasi_meter', null)
        ->assertJsonPath('data.jarak_meter', null)
        ->assertJsonPath('data.ada_foto', false)
        ->assertJsonPath('data.catatan_koreksi', 'Lupa absen, hadir sejak 06:50 menurut buku tamu.')
        ->assertJsonPath('data.dikoreksi_oleh', ['id' => $this->kepsek->id, 'nama' => 'Hj. Umi Kulsum, S.Pd.'])
        ->assertJsonPath('data.dikoreksi_at', '2026-10-02T08:00:00+07:00');

    $log = Activity::query()->where('log_name', 'absensi')->sole();
    expect($log->event)->toBe('dikoreksi')
        ->and($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->subject_id)->toBe(Absensi::query()->where('user_id', $this->kepsek->id)->sole()->id)
        ->and($log->properties->all())->toBe([
            'user_id' => $this->kepsek->id, 'tanggal' => $tanggal, 'sebelum' => null, 'sesudah' => $status,
            'catatan' => 'Lupa absen, hadir sejak 06:50 menurut buku tamu.',
        ]);

    $this->actingAs($this->kepsek)->getJson("/api/v1/absensi/harian?tanggal={$tanggal}")
        ->assertJsonPath('data.peserta.1.masuk.status', $status)
        ->assertJsonPath('data.peserta.1.masuk.dikoreksi_oleh.id', $this->kepsek->id);
})->with([
    'hari ini sebelum jam masuk tutup' => ['2026-10-02', 'hadir'],
    'hari kerja yang sudah lewat' => ['2026-09-29', 'terlambat'],
]);

it('menolak mencatat absen masuk baru yang melanggar aturan, tanpa menyimpan apa pun', function (Closure $isian, string $pesan) {
    aturAbsensi(['tanggal_libur' => ['2026-09-30']]);
    $sebelum = Absensi::query()->count();

    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', $isian($this))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', $pesan);

    expect(Absensi::query()->count())->toBe($sebelum)
        ->and(Activity::query()->where('log_name', 'absensi')->count())->toBe(0);
})->with([
    'wali murid' => [fn ($test) => isianKoreksiBaru(User::factory()->waliMurid()->create()), 'Akun ini bukan peserta absensi. Absensi hanya untuk guru aktif dan Kepala Sekolah.'],
    'guru nonaktif' => [fn ($test) => isianKoreksiBaru($test->fitri), 'Akun ini bukan peserta absensi. Absensi hanya untuk guru aktif dan Kepala Sekolah.'],
    'besok' => [fn ($test) => isianKoreksiBaru($test->dwi, ['tanggal' => '2026-10-03']), 'Tanggal 3 Oktober 2026 belum tiba. Absensi hanya bisa dicatat untuk hari ini atau sebelumnya.'],
    'sebelum tanggal mulai' => [fn ($test) => isianKoreksiBaru($test->dwi, ['tanggal' => '2026-08-31']), 'Absensi baru berlaku mulai 1 September 2026.'],
    'bukan hari kerja' => [fn ($test) => isianKoreksiBaru($test->dwi, ['tanggal' => '2026-09-27']), 'Minggu, 27 September 2026 bukan hari kerja, jadi tidak ada absensi.'],
    'tanggal libur' => [fn ($test) => isianKoreksiBaru($test->dwi, ['tanggal' => '2026-09-30']), '30 September 2026 termasuk tanggal libur, jadi tidak ada absensi.'],
    'sudah absen masuk' => [fn ($test) => isianKoreksiBaru($test->nur, ['tanggal' => '2026-10-01']), 'Absen masuk Nur Aini, S.Pd. tanggal 1 Oktober 2026 sudah tercatat. Koreksi status absensi yang sudah ada.'],
    'sudah ditandai tidak hadir' => [fn ($test) => isianKoreksiBaru($test->dwi, ['tanggal' => '2026-10-01']), 'Absen masuk Dwi Prasetyo tanggal 1 Oktober 2026 sudah tercatat. Koreksi status absensi yang sudah ada.'],
]);

it('memvalidasi isian absen masuk baru', function (array $ubah, string $field) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', isianKoreksiBaru($this->dwi, $ubah))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors([$field]);

    expect(Absensi::query()->where('user_id', $this->dwi->id)->count())->toBe(1);
})->with([
    'tanpa catatan' => [['catatan' => '  '], 'catatan'],
    'status tidak hadir' => [['status' => 'tidak_hadir'], 'status'],
    'tanpa status' => [['status' => null], 'status'],
    'akun tidak ada' => [['user_id' => 999999], 'user_id'],
    'tanpa tanggal' => [['tanggal' => null], 'tanggal'],
    'format tanggal salah' => [['tanggal' => '2/10/2026'], 'tanggal'],
]);

it('membiarkan absen masuk dari Kepala Sekolah saat scheduler menandai tidak hadir', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', isianKoreksiBaru($this->dwi))->assertCreated();
    Carbon::setTestNow('2026-10-02 09:10:00');

    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();

    $hariIni = Absensi::query()->whereDate('tanggal', '2026-10-02')->get()->keyBy('user_id');
    expect($hariIni)->toHaveCount(3)
        ->and($hariIni[$this->dwi->id]->status)->toBe(StatusAbsensi::Hadir)
        ->and($hariIni[$this->dwi->id]->dikoreksi_oleh)->toBe($this->kepsek->id)
        ->and($hariIni[$this->nur->id]->status)->toBe(StatusAbsensi::TidakHadir)
        ->and($hariIni[$this->kepsek->id]->status)->toBe(StatusAbsensi::TidakHadir);
});

it('tidak menghapus absen masuk dari Kepala Sekolah saat tanggalnya dijadikan tanggal libur', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', isianKoreksiBaru($this->dwi, ['tanggal' => '2026-09-29']))->assertCreated();
    $otomatis = Absensi::factory()->for($this->nur)->tidakHadir()->create(['tanggal' => '2026-09-29']);

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['absensi.tanggal_libur' => ['2026-09-29']]])->assertOk();

    expect(Absensi::query()->whereKey($otomatis->id)->exists())->toBeFalse()
        ->and(Absensi::query()->where('user_id', $this->dwi->id)->whereDate('tanggal', '2026-09-29')->sole()->status)->toBe(StatusAbsensi::Hadir);
});

it('menjelaskan absen masuk yang sudah dicatat Kepala Sekolah saat peserta mencoba absen sendiri', function (array $baris, string $pesan) {
    Absensi::factory()->for($this->dwi)->tidakHadir()->create(['tanggal' => '2026-10-02', ...$baris]);

    $this->actingAs($this->dwi)->post('/api/v1/absensi', isianAbsen())
        ->assertStatus(422)
        ->assertJsonPath('message', $pesan);
})->with([
    'dicatat Kepala Sekolah' => [['status' => StatusAbsensi::Hadir], 'Absen masuk hari ini sudah dicatat Kepala Sekolah dengan status Hadir.'],
    'tidak hadir' => [[], 'Absen masuk hari ini sudah tercatat tidak hadir. Hubungi Kepala Sekolah untuk koreksi.'],
]);

it('mengizinkan absen pulang setelah absen masuk dicatat Kepala Sekolah', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/absensi/koreksi', isianKoreksiBaru($this->dwi))->assertCreated();
    Carbon::setTestNow('2026-10-02 12:00:00');

    $this->actingAs($this->dwi)->post('/api/v1/absensi', isianAbsen('pulang'))
        ->assertCreated()
        ->assertJsonPath('data.jenis', JenisAbsensi::Pulang->value);
});
