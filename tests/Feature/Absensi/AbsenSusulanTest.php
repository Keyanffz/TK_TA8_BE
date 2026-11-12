<?php

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\AksesAbsenSusulan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/**
 * Senin, 5 Oktober 2026 pukul 10:00, setelah jam masuk tutup (09:00). Hari kerja Senin–Sabtu, jadi hari kerja
 * terakhir sebelum hari ini adalah Sabtu, 3 Oktober. Lokasi sekolah sengaja belum diatur: absen biasa akan ditolak,
 * absen susulan tidak memeriksa lokasi. Akun dibuat 1 September, tanggal mulai absensi.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-01 06:00:00');
    Storage::fake('local');
    aturAbsensi(['tanggal_mulai' => '2026-09-01']);
    $this->kepsek = buatKepalaSekolah();
    $this->rina = buatGuru()->user;
    $this->rina->update(['name' => 'Rina Kusumawati']);
    $this->dwi = buatGuru()->user;
    Carbon::setTestNow('2026-10-05 10:00:00');
});

function beriAkses(User $kepsek, User $peserta, array $ubah = []): TestResponse
{
    return test()->actingAs($kepsek)->postJson('/api/v1/absensi/susulan', [
        'user_id' => $peserta->id, 'tanggal' => '2026-10-05', 'jenis' => 'masuk', 'catatan' => 'Wifi sekolah mati sejak pagi.', ...$ubah,
    ]);
}

function absenSusulan(User $peserta, int $aksesId): TestResponse
{
    return test()->actingAs($peserta)->post("/api/v1/absensi/susulan/{$aksesId}/absen", ['foto' => UploadedFile::fake()->image('swafoto.jpg', 640, 480)], ['Accept' => 'application/json']);
}

it('memberi akses susulan, memberi tahu peserta, dan mencatat log', function () {
    $id = beriAkses($this->kepsek, $this->rina)
        ->assertCreated()
        ->assertJsonPath('data.tanggal', '2026-10-05')
        ->assertJsonPath('data.jenis', 'masuk')
        ->assertJsonPath('data.berlaku_sampai', '2026-10-05T23:59:59+07:00')
        ->assertJsonPath('data.dipakai_at', null)
        ->assertJsonPath('data.diberikan_oleh.id', $this->kepsek->id)
        ->json('data.id');

    expect($this->rina->notifications()->sole()->data)->toMatchArray([
        'jenis' => 'absensi_susulan_diberikan',
        'pesan' => 'Anda bisa absen masuk untuk Senin, 5 Oktober 2026 dengan swafoto, berlaku sampai 5 Oktober 2026 pukul 23:59. Catatan: Wifi sekolah mati sejak pagi.',
        'url' => '/mudarris/absensi',
    ])->and(Activity::query()->where('log_name', 'absensi')->sole()->event)->toBe('susulan_diberikan');

    $this->actingAs($this->rina)->getJson('/api/v1/absensi/hari-ini')
        ->assertJsonCount(1, 'data.susulan')
        ->assertJsonPath('data.susulan.0.id', $id);
});

it('menerima absen susulan tanpa memeriksa lokasi dan jam, berstatus hadir', function () {
    $id = beriAkses($this->kepsek, $this->rina)->json('data.id');
    Carbon::setTestNow('2026-10-05 13:20:00');

    absenSusulan($this->rina, $id)
        ->assertCreated()
        ->assertJsonPath('message', 'Absen masuk susulan tercatat pukul 13:20.')
        ->assertJsonPath('data.status', 'hadir')
        ->assertJsonPath('data.susulan', true)
        ->assertJsonPath('data.waktu', '2026-10-05T13:20:00+07:00')
        ->assertJsonPath('data.latitude', null)
        ->assertJsonPath('data.ada_foto', true);

    expect(AksesAbsenSusulan::query()->findOrFail($id)->dipakai_at?->format('H:i'))->toBe('13:20')
        ->and(Storage::disk('local')->files('absensi'))->toHaveCount(1);
    $this->actingAs($this->rina)->getJson('/api/v1/absensi/hari-ini')->assertJsonCount(0, 'data.susulan');
});

it('menerima hari ini dan hari kerja terakhir sebelumnya saja', function (string $tanggal, ?string $pesan) {
    $respons = beriAkses($this->kepsek, $this->rina, ['tanggal' => $tanggal]);

    $pesan === null
        ? $respons->assertCreated()
        : $respons->assertStatus(422)->assertJsonPath('code', 'BUSINESS_RULE')->assertJsonPath('message', $pesan);
})->with([
    'hari ini' => ['2026-10-05', null],
    'Sabtu, hari kerja terakhir' => ['2026-10-03', null],
    'Jumat, dua hari kerja lalu' => ['2026-10-02', 'Akses absen susulan hanya untuk hari ini atau hari kerja terakhir sebelumnya. Pilih hari ini atau Sabtu, 3 Oktober 2026.'],
    'Minggu, bukan hari kerja' => ['2026-10-04', 'Minggu, 4 Oktober 2026 bukan hari kerja, jadi tidak ada absensi.'],
    'besok' => ['2026-10-06', 'Tanggal 6 Oktober 2026 belum tiba. Absensi hanya bisa dicatat untuk hari ini atau sebelumnya.'],
    'sebelum tanggal mulai' => ['2026-08-31', 'Absensi baru berlaku mulai 1 September 2026.'],
]);

it('melewati tanggal libur saat mencari hari kerja terakhir', function () {
    aturAbsensi(['tanggal_libur' => ['2026-10-03']]);

    beriAkses($this->kepsek, $this->rina, ['tanggal' => '2026-10-03'])
        ->assertStatus(422)
        ->assertJsonPath('message', '3 Oktober 2026 termasuk tanggal libur, jadi tidak ada absensi.');
    beriAkses($this->kepsek, $this->rina, ['tanggal' => '2026-10-02'])->assertCreated();
});

it('menolak absen susulan kalau tanggalnya dijadikan libur setelah akses diberikan', function () {
    $id = beriAkses($this->kepsek, $this->rina)->json('data.id');
    aturAbsensi(['tanggal_libur' => ['2026-10-05']]);

    absenSusulan($this->rina, $id)
        ->assertStatus(422)
        ->assertJsonPath('message', '5 Oktober 2026 termasuk tanggal libur, jadi tidak ada absensi.');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('menolak akses yang sudah kedaluwarsa', function () {
    $id = beriAkses($this->kepsek, $this->rina, ['tanggal' => '2026-10-03'])->json('data.id');
    Carbon::setTestNow('2026-10-06 00:00:00');

    absenSusulan($this->rina, $id)
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Akses absen susulan ini sudah kedaluwarsa sejak 5 Oktober 2026 pukul 23:59. Minta akses baru ke Kepala Sekolah.');
    expect(Absensi::query()->count())->toBe(0);
});

it('menolak akses yang sudah dipakai', function () {
    $id = beriAkses($this->kepsek, $this->rina)->json('data.id');
    absenSusulan($this->rina, $id)->assertCreated();

    absenSusulan($this->rina, $id)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Akses absen susulan ini sudah dipakai pukul 10:00.');
    expect(Absensi::query()->count())->toBe(1)
        ->and(Storage::disk('local')->files('absensi'))->toHaveCount(1);
});

it('tidak menahan scheduler, lalu mengganti baris tidak hadir dan tetap ada saat tanggalnya dijadikan libur', function () {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $id = beriAkses($this->kepsek, $this->rina)->json('data.id');

    Carbon::setTestNow('2026-10-05 09:10:00');
    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();
    $tidakHadir = Absensi::query()->where('user_id', $this->rina->id)->whereDate('tanggal', '2026-10-05')->sole();
    expect($tidakHadir->status)->toBe(StatusAbsensi::TidakHadir);

    absenSusulan($this->rina, $id)->assertCreated()->assertJsonPath('data.id', $tidakHadir->id);
    $this->artisan('absensi:tandai-tidak-hadir')->assertSuccessful();
    $hariIni = fn () => Absensi::query()->where('user_id', $this->rina->id)->whereDate('tanggal', '2026-10-05')->sole();
    expect($hariIni()->status)->toBe(StatusAbsensi::Hadir);

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['absensi.tanggal_libur' => ['2026-10-05']]])->assertOk();
    expect($hariIni()->susulan)->toBeTrue();
});

it('memberi akses masuk walau sudah ditandai tidak hadir, tetapi tidak kalau absensinya sudah ada', function () {
    Absensi::factory()->for($this->rina)->tidakHadir()->create(['tanggal' => '2026-10-05']);
    beriAkses($this->kepsek, $this->rina)->assertCreated();

    Absensi::factory()->for($this->dwi)->create(['tanggal' => '2026-10-05']);
    beriAkses($this->kepsek, $this->dwi)->assertStatus(422)->assertJsonPath('code', 'BUSINESS_RULE');

    $dikoreksi = Absensi::factory()->for($this->rina)->tidakHadir()->create(['tanggal' => '2026-10-03', 'dikoreksi_at' => now(), 'catatan_koreksi' => 'Tidak datang.']);
    beriAkses($this->kepsek, $this->rina, ['tanggal' => '2026-10-03'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Absen masuk Rina Kusumawati tanggal 3 Oktober 2026 sudah tercatat. Koreksi absensinya di Rekap Absensi.');
    expect($dikoreksi->fresh()->status)->toBe(StatusAbsensi::TidakHadir);
});

it('menolak akses kedua yang sama selama yang pertama belum dipakai', function () {
    beriAkses($this->kepsek, $this->rina)->assertCreated();

    beriAkses($this->kepsek, $this->rina)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Rina Kusumawati masih punya akses absen masuk susulan untuk tanggal 5 Oktober 2026 yang belum dipakai.');
    beriAkses($this->kepsek, $this->rina, ['jenis' => 'pulang'])->assertCreated();
});

it('hanya menerima absen pulang susulan setelah absen masuk hadir atau terlambat', function () {
    $pulang = beriAkses($this->kepsek, $this->rina, ['jenis' => 'pulang'])->json('data.id');
    absenSusulan($this->rina, $pulang)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Absen pulang susulan hanya bisa setelah absen masuk tanggal 5 Oktober 2026 tercatat hadir atau terlambat.');

    $masuk = beriAkses($this->kepsek, $this->rina)->json('data.id');
    absenSusulan($this->rina, $masuk)->assertCreated();
    absenSusulan($this->rina, $pulang)
        ->assertCreated()
        ->assertJsonPath('data.jenis', 'pulang')
        ->assertJsonPath('data.status', null)
        ->assertJsonPath('data.pulang_awal', false);
});

it('tidak memberi akses pulang untuk peserta yang izin atau sakit', function () {
    Absensi::factory()->for($this->rina)->create(['tanggal' => '2026-10-05', 'status' => StatusAbsensi::Sakit]);

    beriAkses($this->kepsek, $this->rina, ['jenis' => 'pulang'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Rina Kusumawati tercatat sakit tanggal 5 Oktober 2026, jadi tidak ada absen pulang.');
});

it('hanya Kepala Sekolah yang memberi akses, dan tidak untuk dirinya sendiri', function () {
    $adminAbsensi = buatGuru()->user;
    $adminAbsensi->guru->izin()->create(['modul' => 'laporan_absensi', 'level' => 'kelola']);

    beriAkses($adminAbsensi, $this->rina)->assertForbidden();
    beriAkses(User::factory()->waliMurid()->create(), $this->rina)->assertForbidden();
    beriAkses($this->kepsek, $this->kepsek)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Kepala Sekolah tidak memberi akses susulan untuk dirinya sendiri. Catat absensi Anda di Rekap Absensi.');
});

it('menolak akses milik peserta lain dengan 404', function () {
    $id = beriAkses($this->kepsek, $this->rina)->json('data.id');

    absenSusulan($this->dwi, $id)->assertNotFound();
    expect(AksesAbsenSusulan::query()->findOrFail($id)->dipakai_at)->toBeNull();
});

it('mencabut akses yang belum dipakai dan menolak mencabut yang sudah dipakai', function () {
    $belum = beriAkses($this->kepsek, $this->rina)->json('data.id');
    $this->actingAs($this->kepsek)->deleteJson("/api/v1/absensi/susulan/{$belum}")->assertOk();
    expect(AksesAbsenSusulan::query()->find($belum))->toBeNull();

    $dipakai = beriAkses($this->kepsek, $this->rina)->json('data.id');
    absenSusulan($this->rina, $dipakai)->assertCreated();
    $this->actingAs($this->kepsek)->deleteJson("/api/v1/absensi/susulan/{$dipakai}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('menampilkan akses susulan peserta di absensi harian, termasuk yang sudah dipakai', function () {
    $id = beriAkses($this->kepsek, $this->rina)->json('data.id');
    absenSusulan($this->rina, $id)->assertCreated();

    $peserta = collect($this->actingAs($this->kepsek)->getJson('/api/v1/absensi/harian?tanggal=2026-10-05')->assertOk()->json('data.peserta'))->keyBy('user.id');

    expect($peserta[$this->rina->id]['akses_susulan'])->toHaveCount(1)
        ->and($peserta[$this->rina->id]['akses_susulan'][0]['dipakai_at'])->toBe('2026-10-05T10:00:00+07:00')
        ->and($peserta[$this->rina->id]['masuk']['susulan'])->toBeTrue()
        ->and($peserta[$this->dwi->id]['akses_susulan'])->toBe([]);
});
