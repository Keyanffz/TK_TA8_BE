<?php

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Models\Kelas;
use App\Models\LiburKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Senin, 5 Oktober 2026, tahun ajaran 2026/2027 (13 Juli 2026 sampai 25 Juni 2027). TK A1: wali kelas Bu Aini, guru
 * pendamping Bu Rina. Bu Dewi wali kelas TK B1.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $this->kepsek = buatKepalaSekolah();
    $this->tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-25']);
    $this->buAini = buatGuru();
    $this->buRina = buatGuru();
    $this->buDewi = buatGuru();
    $this->kelas = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id, 'guru_pendamping_id' => $this->buRina->id]);
    $this->kelasB1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK B1', 'wali_kelas_id' => $this->buDewi->id]);
});

function liburKelas(Kelas $kelas, string $mulai, string $selesai, string $keterangan = 'Libur semester ganjil'): LiburKelas
{
    return $kelas->libur()->create(['tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai, 'keterangan' => $keterangan]);
}

it('menampilkan hari sekolah dan libur ke pengampu, dengan bisa_diatur hanya untuk wali kelas dan Kepala Sekolah', function (Closure $siapa, bool $bisaDiatur) {
    liburKelas($this->kelas, '2026-12-21', '2027-01-01');
    liburKelas($this->kelas, '2026-10-30', '2026-10-30', 'Rapat guru');

    $this->actingAs($siapa($this)->fresh())->getJson("/api/v1/kelas/{$this->kelas->id}/hari-sekolah")
        ->assertOk()
        ->assertJsonPath('data.hari_sekolah', [1, 2, 3, 4, 5, 6])
        ->assertJsonPath('data.libur.0.tanggal_mulai', '2026-10-30')
        ->assertJsonPath('data.libur.0.tanggal_selesai', '2026-10-30')
        ->assertJsonPath('data.libur.1.keterangan', 'Libur semester ganjil')
        ->assertJsonPath('data.bisa_diatur', $bisaDiatur);
})->with([
    'wali kelas' => [fn ($test) => $test->buAini->user, true],
    'guru pendamping' => [fn ($test) => $test->buRina->user, false],
    'Kepala Sekolah' => [fn ($test) => $test->kepsek, true],
]);

it('menolak guru kelas lain dengan 404 dan wali murid dengan 403', function () {
    $this->buDewi->izin()->create(['modul' => ModulIzin::DataMurid, 'level' => LevelIzin::Kelola]);
    $wali = User::factory()->waliMurid()->create();

    $this->actingAs($this->buDewi->user)->getJson("/api/v1/kelas/{$this->kelas->id}/hari-sekolah")->assertNotFound();
    $this->actingAs($this->buDewi->user)->putJson("/api/v1/kelas/{$this->kelas->id}/hari-sekolah", ['hari_sekolah' => [1]])->assertNotFound();
    $this->actingAs($wali)->getJson("/api/v1/kelas/{$this->kelas->id}/hari-sekolah")->assertForbidden();
    expect($this->kelas->fresh()?->hari_sekolah)->toBe([1, 2, 3, 4, 5, 6]);
});

it('membolehkan wali kelas dan Kepala Sekolah mengubah hari sekolah, tidak untuk guru pendamping', function () {
    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/hari-sekolah", ['hari_sekolah' => [5, 1, 3, 2, 4]])
        ->assertOk()
        ->assertJsonPath('message', 'Hari sekolah TK A1 disimpan.')
        ->assertJsonPath('data.hari_sekolah', [1, 2, 3, 4, 5]);
    $this->actingAs($this->kepsek)->putJson("/api/v1/kelas/{$this->kelasB1->id}/hari-sekolah", ['hari_sekolah' => [1, 2, 3, 4]])->assertOk();

    $this->actingAs($this->buRina->user)->putJson("/api/v1/kelas/{$this->kelas->id}/hari-sekolah", ['hari_sekolah' => [1]])
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN')
        ->assertJsonPath('message', 'Hari sekolah dan tanggal libur kelas hanya bisa diubah wali kelas dan Kepala Sekolah.');

    expect($this->kelas->fresh()?->hari_sekolah)->toBe([1, 2, 3, 4, 5])
        ->and($this->kelasB1->fresh()?->hari_sekolah)->toBe([1, 2, 3, 4]);
});

it('memvalidasi hari sekolah', function (array $hari, string $field) {
    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/hari-sekolah", ['hari_sekolah' => $hari])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'kosong' => [[], 'hari_sekolah'],
    'di luar 1-7' => [[1, 8], 'hari_sekolah.1'],
    'dobel' => [[2, 2], 'hari_sekolah.0'],
]);

it('menambah libur rentang tanggal yang mengunci absensi murid di setiap tanggalnya', function () {
    $this->actingAs($this->buAini->user)->postJson("/api/v1/kelas/{$this->kelas->id}/libur", [
        'tanggal_mulai' => '2026-09-28', 'tanggal_selesai' => '2026-09-30', 'keterangan' => 'Kegiatan manasik haji.',
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'Libur 28 September 2026 sampai 30 September 2026 ditambahkan.')
        ->assertJsonPath('data.libur.0.keterangan', 'Kegiatan manasik haji');

    $harian = fn (string $tanggal) => $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid?tanggal={$tanggal}")->json('data');
    expect($harian('2026-09-29')['alasan'])->toBe('Selasa, 29 September 2026 termasuk libur TK A1: Kegiatan manasik haji.')
        ->and($harian('2026-09-30')['bisa_dicatat'])->toBeFalse()
        ->and($harian('2026-10-01')['bisa_dicatat'])->toBeTrue();
    $this->actingAs($this->buDewi->user)->getJson("/api/v1/kelas/{$this->kelasB1->id}/absensi-murid?tanggal=2026-09-29")
        ->assertJsonPath('data.bisa_dicatat', true);
});

it('menyimpan libur satu hari kalau tanggal selesai kosong', function () {
    $this->actingAs($this->kepsek)->postJson("/api/v1/kelas/{$this->kelas->id}/libur", ['tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => null, 'keterangan' => 'Hari Pahlawan'])
        ->assertCreated()
        ->assertJsonPath('message', 'Libur 10 November 2026 ditambahkan.')
        ->assertJsonPath('data.libur.0.tanggal_mulai', '2026-11-10')
        ->assertJsonPath('data.libur.0.tanggal_selesai', '2026-11-10');
});

it('menolak libur di luar tahun ajaran, bertumpuk dengan libur lain, atau selesai sebelum mulai', function (array $isi, string $pesan) {
    liburKelas($this->kelas, '2026-12-21', '2027-01-01');

    $respons = $this->actingAs($this->buAini->user)->postJson("/api/v1/kelas/{$this->kelas->id}/libur", ['keterangan' => 'Libur tambahan', ...$isi])->assertStatus(422);

    expect($respons->json('errors.tanggal_selesai.0') ?? $respons->json('message'))->toBe($pesan)
        ->and(LiburKelas::query()->count())->toBe(1);
})->with([
    'melewati akhir tahun ajaran' => [['tanggal_mulai' => '2027-06-24', 'tanggal_selesai' => '2027-06-30'], 'Tanggal libur harus di dalam tahun ajaran 2026/2027 (13 Juli 2026 sampai 25 Juni 2027).'],
    'menutupi awal libur lain' => [['tanggal_mulai' => '2026-12-18', 'tanggal_selesai' => '2026-12-21'], 'Tanggal itu bertumpuk dengan libur "Libur semester ganjil" (21 Desember 2026 sampai 1 Januari 2027). Hapus libur itu dulu kalau ingin mengubahnya.'],
    'di dalam libur lain' => [['tanggal_mulai' => '2026-12-25'], 'Tanggal itu bertumpuk dengan libur "Libur semester ganjil" (21 Desember 2026 sampai 1 Januari 2027). Hapus libur itu dulu kalau ingin mengubahnya.'],
    'selesai sebelum mulai' => [['tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-09'], 'Tanggal selesai tidak boleh sebelum tanggal mulai.'],
]);

it('membolehkan libur yang bersebelahan dengan libur lain', function () {
    liburKelas($this->kelas, '2026-12-21', '2027-01-01');

    $this->actingAs($this->buAini->user)->postJson("/api/v1/kelas/{$this->kelas->id}/libur", ['tanggal_mulai' => '2027-01-02', 'keterangan' => 'Tambahan libur'])->assertCreated();
    liburKelas($this->kelasB1, '2026-12-25', '2026-12-25');
    $this->actingAs($this->buAini->user)->postJson("/api/v1/kelas/{$this->kelas->id}/libur", ['tanggal_mulai' => '2026-12-18', 'tanggal_selesai' => '2026-12-20', 'keterangan' => 'Pentas akhir semester'])->assertCreated();

    expect($this->kelas->libur()->count())->toBe(3);
});

it('menghapus libur kelas itu saja, dan menolak guru pendamping', function () {
    $libur = liburKelas($this->kelas, '2026-10-30', '2026-10-30', 'Rapat guru');
    $liburB1 = liburKelas($this->kelasB1, '2026-10-30', '2026-10-30', 'Rapat guru');

    $this->actingAs($this->buRina->user)->deleteJson("/api/v1/kelas/{$this->kelas->id}/libur/{$libur->id}")->assertForbidden();
    $this->actingAs($this->buAini->user)->deleteJson("/api/v1/kelas/{$this->kelas->id}/libur/{$liburB1->id}")->assertNotFound();
    $this->actingAs($this->buAini->user)->deleteJson("/api/v1/kelas/{$this->kelas->id}/libur/{$libur->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Libur 30 Oktober 2026 dihapus.')
        ->assertJsonPath('data.libur', []);

    expect(LiburKelas::query()->pluck('id')->all())->toBe([$liburB1->id]);
});

it('memulai kelas baru dengan hari kerja absensi guru sebagai hari sekolah', function () {
    aturAbsensi(['hari_kerja' => [1, 2, 3, 4, 5]]);

    $id = $this->actingAs($this->kepsek)->postJson('/api/v1/kelas', ['tahun_ajaran_id' => $this->tahunAjaran->id, 'nama' => 'TK A2', 'tingkat' => 'A'])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->kepsek)->getJson("/api/v1/kelas/{$id}/hari-sekolah")->assertJsonPath('data.hari_sekolah', [1, 2, 3, 4, 5]);
});

it('menyalin hari kerja absensi guru ke kelas yang sudah ada saat migration', function () {
    aturAbsensi(['hari_kerja' => [1, 2, 3, 4, 5]]);
    $migration = require database_path('migrations/2026_11_14_110000_tambah_hari_sekolah_ke_kelas_table.php');

    $migration->down();
    $migration->up();

    expect(Kelas::query()->pluck('hari_sekolah')->all())->toBe([[1, 2, 3, 4, 5], [1, 2, 3, 4, 5]]);
});
