<?php

use App\Enums\StatusAbsensi;
use App\Enums\StatusAkun;
use App\Models\Absensi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
