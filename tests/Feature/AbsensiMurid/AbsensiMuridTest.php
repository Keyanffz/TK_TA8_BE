<?php

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Enums\StatusAbsensiMurid;
use App\Enums\StatusKelasMurid;
use App\Models\AbsensiMurid;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Senin, 5 Oktober 2026. TK A1: wali kelas Bu Aini, guru pendamping Bu Rina, murid Aisyah dan Bima (aktif) serta
 * Citra (sudah keluar dari kelas). Bu Dewi wali kelas TK B1. Hari sekolah kedua kelas Senin–Sabtu.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-25']);
    $this->buAini = buatGuru();
    $this->buRina = buatGuru();
    $this->buDewi = buatGuru();
    $this->kelas = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id, 'guru_pendamping_id' => $this->buRina->id]);
    Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK B1', 'wali_kelas_id' => $this->buDewi->id]);

    $this->aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri']);
    $this->bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $this->citra = Murid::factory()->create(['nama_lengkap' => 'Citra Lestari']);
    $this->kelas->murid()->attach([$this->aisyah->id, $this->bima->id]);
    $this->kelas->murid()->attach($this->citra->id, ['status' => StatusKelasMurid::Keluar]);
});

function isiKelas(array $ubah = []): array
{
    return [
        'tanggal' => '2026-10-05',
        'absensi' => [
            ['murid_id' => test()->aisyah->id, 'status' => 'hadir'],
            ['murid_id' => test()->bima->id, 'status' => 'sakit', 'keterangan' => 'Demam sejak semalam.'],
        ],
        ...$ubah,
    ];
}

it('membolehkan wali kelas, guru pendamping, dan Kepala Sekolah mencatat absensi satu kelas', function (Closure $siapa) {
    $this->actingAs($siapa($this)->fresh())->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas())
        ->assertOk()
        ->assertJsonPath('message', 'Absensi TK A1 tanggal 5 Oktober 2026 disimpan.')
        ->assertJsonPath('data.murid.0.status', 'hadir')
        ->assertJsonPath('data.murid.1.status', 'sakit')
        ->assertJsonPath('data.murid.1.keterangan', 'Demam sejak semalam.');

    expect(AbsensiMurid::query()->count())->toBe(2);
})->with([
    'wali kelas' => [fn ($test) => $test->buAini->user],
    'guru pendamping' => [fn ($test) => $test->buRina->user],
    'Kepala Sekolah' => [fn ($test) => $test->kepsek],
]);

it('menolak guru kelas lain, termasuk yang berizin data murid, dengan 404 dan wali murid dengan 403', function () {
    $this->buDewi->izin()->create(['modul' => ModulIzin::DataMurid, 'level' => LevelIzin::Kelola]);

    $this->actingAs($this->buDewi->user)->getJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid")->assertNotFound();
    $this->actingAs($this->buDewi->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas())->assertNotFound();
    $this->actingAs(User::factory()->waliMurid()->create())->getJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid")->assertForbidden();
    expect(AbsensiMurid::query()->count())->toBe(0);
});

it('menolak tanggal yang belum tiba, bukan hari sekolah, libur kelas, atau di luar tahun ajaran', function (string $tanggal, string $pesan) {
    $this->kelas->libur()->create(['tanggal_mulai' => '2026-10-01', 'tanggal_selesai' => '2026-10-02', 'keterangan' => 'Rapat guru se-kecamatan']);

    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas(['tanggal' => $tanggal]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', $pesan);
    $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid?tanggal={$tanggal}")
        ->assertJsonPath('data.bisa_dicatat', false)
        ->assertJsonPath('data.alasan', $pesan);
})->with([
    'besok' => ['2026-10-06', 'Selasa, 6 Oktober 2026 belum tiba. Absensi murid hanya untuk hari ini atau sebelumnya.'],
    'Minggu' => ['2026-10-04', 'Minggu, 4 Oktober 2026 bukan hari sekolah TK A1.'],
    'hari terakhir rentang libur' => ['2026-10-02', 'Jumat, 2 Oktober 2026 termasuk libur TK A1: Rapat guru se-kecamatan.'],
    'sebelum tahun ajaran' => ['2026-07-10', 'Jumat, 10 Juli 2026 di luar tahun ajaran 2026/2027.'],
]);

it('mengikuti hari sekolah dan libur kelas, bukan hari kerja dan tanggal libur absensi guru', function () {
    aturAbsensi(['hari_kerja' => [1, 2, 3, 4, 5], 'tanggal_libur' => ['2026-10-05']]);

    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas())->assertOk();
    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas(['tanggal' => '2026-10-03']))->assertOk();

    $this->kelas->update(['hari_sekolah' => [1, 2, 3, 4, 5]]);
    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas(['tanggal' => '2026-10-03']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Sabtu, 3 Oktober 2026 bukan hari sekolah TK A1.');
});

it('menyimpan satu kelas dalam satu transaksi dan menolak kalau ada murid aktif yang terlewat', function () {
    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas(['absensi' => [
        ['murid_id' => $this->aisyah->id, 'status' => 'hadir'],
    ]]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Absensi harus memuat semua murid aktif di kelas ini (1 murid belum diisi).');

    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas(['absensi' => [
        ['murid_id' => $this->aisyah->id, 'status' => 'hadir'],
        ['murid_id' => $this->bima->id, 'status' => 'hadir'],
        ['murid_id' => $this->citra->id, 'status' => 'hadir'],
    ]]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(AbsensiMurid::query()->count())->toBe(0);
});

it('membatalkan semua baris kalau satu baris gagal disimpan', function () {
    AbsensiMurid::creating(function (AbsensiMurid $baris): void {
        if ($baris->murid_id === test()->bima->id) {
            throw new RuntimeException('Sambungan database terputus.');
        }
    });

    expect(fn () => $this->withoutExceptionHandling()->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas()))
        ->toThrow(RuntimeException::class);
    expect(AbsensiMurid::query()->count())->toBe(0);
});

it('mengubah absensi tanggal yang sudah lewat tanpa membuat baris ganda', function () {
    $this->actingAs($this->buAini->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas(['tanggal' => '2026-10-03']))->assertOk();
    $this->actingAs($this->buRina->user)->putJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid", isiKelas(['tanggal' => '2026-10-03', 'absensi' => [
        ['murid_id' => $this->aisyah->id, 'status' => 'izin', 'keterangan' => 'Acara keluarga.'],
        ['murid_id' => $this->bima->id, 'status' => 'hadir'],
    ]]))->assertOk();

    expect(AbsensiMurid::query()->count())->toBe(2)
        ->and(AbsensiMurid::query()->where('murid_id', $this->aisyah->id)->sole()->only(['status', 'dicatat_oleh']))
        ->toBe(['status' => StatusAbsensiMurid::Izin, 'dicatat_oleh' => $this->buRina->user_id]);
});

it('menampilkan murid aktif dengan status kosong sebelum dicatat', function () {
    $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid")
        ->assertOk()
        ->assertJsonPath('data.tanggal', '2026-10-05')
        ->assertJsonPath('data.bisa_dicatat', true)
        ->assertJsonCount(2, 'data.murid')
        ->assertJsonPath('data.murid.0.murid.nama_lengkap', 'Aisyah Putri')
        ->assertJsonPath('data.murid.0.status', null);
});

it('merekap satu bulan per murid, termasuk murid yang sudah keluar tetapi punya absensi', function () {
    $catat = fn (Murid $murid, string $tanggal, StatusAbsensiMurid $status) => AbsensiMurid::factory()->create([
        'murid_id' => $murid->id, 'kelas_id' => $this->kelas->id, 'tanggal' => $tanggal, 'status' => $status, 'dicatat_oleh' => $this->buAini->user_id,
    ]);
    $catat($this->aisyah, '2026-10-01', StatusAbsensiMurid::Hadir);
    $catat($this->aisyah, '2026-10-02', StatusAbsensiMurid::Alpa);
    $catat($this->bima, '2026-10-01', StatusAbsensiMurid::Sakit);
    $catat($this->citra, '2026-10-01', StatusAbsensiMurid::Izin);
    $catat($this->aisyah, '2026-09-30', StatusAbsensiMurid::Hadir);

    $rekap = $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid/rekap?bulan=2026-10")->assertOk()->json('data');

    expect($rekap['hari_tercatat'])->toBe(2)
        ->and(collect($rekap['murid'])->mapWithKeys(fn (array $baris) => [$baris['murid']['nama_lengkap'] => [$baris['hadir'], $baris['izin'], $baris['sakit'], $baris['alpa']]])->all())
        ->toBe([
            'Aisyah Putri' => [1, 0, 0, 1],
            'Bima Saputra' => [0, 0, 1, 0],
            'Citra Lestari' => [0, 1, 0, 0],
        ]);
});

it('tidak menghitung absensi pada tanggal yang kemudian dijadikan libur atau dilepas dari hari sekolah, tanpa menghapusnya', function () {
    $catat = fn (string $tanggal, StatusAbsensiMurid $status) => AbsensiMurid::factory()->create([
        'murid_id' => $this->aisyah->id, 'kelas_id' => $this->kelas->id, 'tanggal' => $tanggal, 'status' => $status, 'dicatat_oleh' => $this->buAini->user_id,
    ]);
    $catat('2026-10-01', StatusAbsensiMurid::Hadir);
    $catat('2026-10-02', StatusAbsensiMurid::Alpa);
    $catat('2026-10-03', StatusAbsensiMurid::Sakit);
    $rekap = fn () => $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid/rekap?bulan=2026-10")->assertOk()->json('data');

    $libur = $this->actingAs($this->buAini->user)->postJson("/api/v1/kelas/{$this->kelas->id}/libur", ['tanggal_mulai' => '2026-10-02', 'keterangan' => 'Banjir'])
        ->assertCreated()
        ->assertJsonPath('message', 'Libur 2 Oktober 2026 ditambahkan. Absensi murid yang sudah tercatat pada tanggal itu tetap tersimpan, tetapi tidak dihitung di rekap.')
        ->json('data.libur.0.id');
    $this->kelas->update(['hari_sekolah' => [1, 2, 3, 4, 5]]);

    expect($rekap()['hari_tercatat'])->toBe(1)
        ->and(array_slice($rekap()['murid'][0], 1))->toBe(['hadir' => 1, 'izin' => 0, 'sakit' => 0, 'alpa' => 0])
        ->and(AbsensiMurid::query()->count())->toBe(3);
    $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelas->id}/absensi-murid?tanggal=2026-10-02")
        ->assertJsonPath('data.bisa_dicatat', false)
        ->assertJsonPath('data.murid.0.status', 'alpa');

    $this->actingAs($this->buAini->user)->deleteJson("/api/v1/kelas/{$this->kelas->id}/libur/{$libur}")->assertOk();
    expect($rekap()['hari_tercatat'])->toBe(2)
        ->and($rekap()['murid'][0]['alpa'])->toBe(1);
});

it('mengekspor rekap bulanan sebagai Excel dan PDF', function () {
    AbsensiMurid::factory()->create(['murid_id' => $this->aisyah->id, 'kelas_id' => $this->kelas->id, 'tanggal' => '2026-10-01', 'dicatat_oleh' => $this->buAini->user_id]);

    $excel = $this->actingAs($this->buRina->user)->get("/api/v1/kelas/{$this->kelas->id}/absensi-murid/rekap/export?bulan=2026-10")
        ->assertOk()
        ->assertDownload('absensi-murid-tk-a1-2026-10.xlsx');
    $baris = IOFactory::load($excel->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(formatData: false);
    expect($baris[0])->toBe(['NIS', 'Nama', 'Hadir', 'Izin', 'Sakit', 'Alpa'])
        ->and($baris[1])->toBe([$this->aisyah->nis, 'Aisyah Putri', 1, 0, 0, 0]);

    $pdf = $this->actingAs($this->kepsek)->get("/api/v1/kelas/{$this->kelas->id}/absensi-murid/rekap/export?bulan=2026-10&format=pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');

    $this->actingAs($this->buDewi->user)->get("/api/v1/kelas/{$this->kelas->id}/absensi-murid/rekap/export")->assertNotFound();
});
