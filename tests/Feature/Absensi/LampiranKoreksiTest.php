<?php

use App\Models\Absensi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Jumat, 2 Oktober 2026 pukul 10:00. Bu Nur ditandai tidak hadir pada Kamis 1 Oktober dan belum punya absensi
 * Rabu 30 September.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-01 06:00:00');
    Storage::fake('local');
    aturAbsensi(['tanggal_mulai' => '2026-09-01']);
    $this->kepsek = buatKepalaSekolah();
    $this->nur = buatGuru()->user;
    Carbon::setTestNow('2026-10-02 10:00:00');
    $this->tidakHadir = Absensi::factory()->for($this->nur)->tidakHadir()->create(['tanggal' => '2026-10-01']);
});

function koreksiDenganLampiran(Absensi $absensi, UploadedFile $lampiran, string $status = 'sakit'): TestResponse
{
    return test()->actingAs(test()->kepsek)->patch("/api/v1/absensi/{$absensi->id}/koreksi", [
        'status' => $status, 'catatan' => 'Surat dokter menyusul.', 'lampiran' => $lampiran,
    ], ['Accept' => 'application/json']);
}

it('menyimpan lampiran PDF saat koreksi dan mengirim signed URL-nya', function () {
    koreksiDenganLampiran($this->tidakHadir, UploadedFile::fake()->create('surat-dokter.pdf', 200, 'application/pdf'))
        ->assertOk()
        ->assertJsonPath('data.status', 'sakit')
        ->assertJsonPath('data.lampiran.jenis', 'pdf')
        ->assertJsonPath('data.lampiran.url', fn (string $url): bool => str_contains($url, '/api/v1/media/'));

    expect(Storage::disk('local')->files('absensi/lampiran'))->toHaveCount(1)
        ->and($this->tidakHadir->fresh()->lampiran_path)->toEndWith('.pdf');
    $this->actingAs($this->nur)->getJson('/api/v1/absensi?bulan=2026-10')->assertJsonPath('data.0.lampiran.jenis', 'pdf');
});

it('menyimpan foto lampiran saat mencatat absen masuk yang terlupa', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/absensi/koreksi', [
        'user_id' => $this->nur->id, 'tanggal' => '2026-09-30', 'status' => 'hadir', 'catatan' => 'Hadir rapat di dinas.',
        'lampiran' => UploadedFile::fake()->image('surat-tugas.png', 800, 600),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.lampiran.jenis', 'gambar');

    expect(Storage::disk('local')->files('absensi/lampiran')[0])->toEndWith('.jpg');
});

it('mengganti lampiran lama saat koreksi berikutnya membawa lampiran baru', function () {
    koreksiDenganLampiran($this->tidakHadir, UploadedFile::fake()->create('pertama.pdf', 100, 'application/pdf'))->assertOk();
    $lama = $this->tidakHadir->fresh()->lampiran_path;

    koreksiDenganLampiran($this->tidakHadir, UploadedFile::fake()->image('kedua.jpg'), 'izin')->assertOk();

    expect(Storage::disk('local')->exists($lama))->toBeFalse()
        ->and(Storage::disk('local')->files('absensi/lampiran'))->toHaveCount(1);
});

it('tetap menyimpan lampiran lama kalau koreksi tidak membawa lampiran', function () {
    koreksiDenganLampiran($this->tidakHadir, UploadedFile::fake()->create('surat.pdf', 100, 'application/pdf'))->assertOk();

    $this->actingAs($this->kepsek)->patchJson("/api/v1/absensi/{$this->tidakHadir->id}/koreksi", ['status' => 'izin', 'catatan' => 'Ternyata izin.'])
        ->assertOk()
        ->assertJsonPath('data.lampiran.jenis', 'pdf');
});

it('menolak lampiran yang bukan PDF atau gambar', function () {
    koreksiDenganLampiran($this->tidakHadir, UploadedFile::fake()->create('surat.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
        ->assertStatus(422)
        ->assertJsonPath('errors.lampiran.0', 'Lampiran harus berupa PDF atau gambar (JPG, PNG, WebP).');
});

it('tidak menyimpan file lampiran kalau koreksi ditolak', function () {
    koreksiDenganLampiran($this->tidakHadir, UploadedFile::fake()->create('surat.pdf', 100, 'application/pdf'), 'tidak_hadir')
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('menghapus lampiran bersama foto setelah masa simpan', function () {
    aturAbsensi(['masa_simpan_foto_bulan' => 1]);
    Storage::disk('local')->put('absensi/lama.jpg', 'foto');
    Storage::disk('local')->put('absensi/lampiran/lama.pdf', 'pdf');
    $lama = Absensi::factory()->for($this->nur)->create(['tanggal' => '2026-08-20', 'foto_path' => 'absensi/lama.jpg', 'lampiran_path' => 'absensi/lampiran/lama.pdf']);

    $this->artisan('absensi:hapus-foto-lama')->expectsOutput('2 foto dan lampiran absensi dihapus.')->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($lama->fresh()->only(['foto_path', 'lampiran_path']))->toBe(['foto_path' => null, 'lampiran_path' => null]);
});
