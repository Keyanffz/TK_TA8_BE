<?php

use App\Models\Kelas;
use App\Models\Pengaturan;
use App\Models\TahunAjaran;
use App\Models\User;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
});

function dataTahunAjaran(array $timpa = []): array
{
    return [
        'nama' => '2027/2028',
        'tanggal_mulai' => '2027-07-12',
        'tanggal_selesai' => '2028-06-23',
        ...$timpa,
    ];
}

it('menampilkan daftar tahun ajaran ke Kepala Sekolah dan guru, terbaru lebih dulu', function () {
    TahunAjaran::factory()->create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14']);
    TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13']);

    $this->actingAs(buatGuru()->user)->getJson('/api/v1/tahun-ajaran')
        ->assertOk()
        ->assertJsonPath('data.0.nama', '2026/2027')
        ->assertJsonPath('data.0.is_aktif', true)
        ->assertJsonPath('data.0.tanggal_mulai', '2026-07-13')
        ->assertJsonPath('data.1.nama', '2025/2026')
        ->assertJsonPath('meta.total', 2);
});

it('menutup daftar tahun ajaran untuk wali murid', function () {
    $this->actingAs(User::factory()->waliMurid()->create())->getJson('/api/v1/tahun-ajaran')
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('mengaktifkan tahun ajaran pertama secara otomatis', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tahun-ajaran', dataTahunAjaran())
        ->assertCreated()
        ->assertJsonPath('data.nama', '2027/2028')
        ->assertJsonPath('data.semester_aktif', 1)
        ->assertJsonPath('data.is_aktif', true);
});

it('membuat tahun ajaran berikutnya dalam keadaan tidak aktif', function () {
    TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tahun-ajaran', dataTahunAjaran())
        ->assertCreated()
        ->assertJsonPath('data.is_aktif', false);

    expect(TahunAjaran::query()->aktif()->pluck('nama')->all())->toBe(['2026/2027']);
});

it('memvalidasi isian tahun ajaran', function (array $data, string $field) {
    TahunAjaran::factory()->create(['nama' => '2026/2027']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tahun-ajaran', dataTahunAjaran($data))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'format nama salah' => [['nama' => '2027-2028'], 'nama'],
    'tahun tidak berurutan' => [['nama' => '2027/2029'], 'nama'],
    'nama sudah dipakai' => [['nama' => '2026/2027'], 'nama'],
    'tanggal selesai sebelum mulai' => [['tanggal_selesai' => '2027-07-01'], 'tanggal_selesai'],
    'semester selain 1 atau 2' => [['semester_aktif' => 3], 'semester_aktif'],
]);

it('memperbarui tahun ajaran tanpa mengubah status aktif', function () {
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);

    $this->actingAs($this->kepsek)->putJson("/api/v1/tahun-ajaran/{$tahunAjaran->id}", [
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-13',
        'tanggal_selesai' => '2027-06-25',
        'semester_aktif' => 2,
        'is_aktif' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.semester_aktif', 2)
        ->assertJsonPath('data.is_aktif', true);
});

it('mengaktifkan satu tahun ajaran dan menonaktifkan yang lain', function () {
    $lama = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $baru = TahunAjaran::factory()->create(['nama' => '2027/2028']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/tahun-ajaran/{$baru->id}/aktifkan")
        ->assertOk()
        ->assertJsonPath('data.is_aktif', true)
        ->assertJsonPath('message', 'Tahun ajaran 2027/2028 sekarang aktif.');

    expect($lama->fresh()?->is_aktif)->toBeFalse()
        ->and(TahunAjaran::query()->aktif()->count())->toBe(1);
});

it('menghapus tahun ajaran yang belum dipakai', function () {
    $tahunAjaran = TahunAjaran::factory()->create();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/tahun-ajaran/{$tahunAjaran->id}")->assertOk();

    expect(TahunAjaran::query()->find($tahunAjaran->id))->toBeNull();
});

it('menolak menghapus tahun ajaran yang aktif, sudah punya kelas, atau menjadi tujuan PPDB', function (Closure $siapkan) {
    $tahunAjaran = $siapkan();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/tahun-ajaran/{$tahunAjaran->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(TahunAjaran::query()->find($tahunAjaran->id))->not->toBeNull();
})->with([
    'sedang aktif' => [fn () => TahunAjaran::factory()->aktif()->create()],
    'sudah punya kelas' => [fn () => Kelas::factory()->create()->tahunAjaran],
    'tujuan PPDB' => [function () {
        $tahunAjaran = TahunAjaran::factory()->create();
        Pengaturan::query()->create(['kunci' => 'ppdb.tahun_ajaran_id', 'nilai' => $tahunAjaran->id, 'grup' => 'ppdb']);

        return $tahunAjaran;
    }],
]);

it('hanya Kepala Sekolah yang bisa mengubah tahun ajaran', function () {
    $tahunAjaran = TahunAjaran::factory()->create();

    $this->actingAs(buatGuru()->user)->postJson("/api/v1/tahun-ajaran/{$tahunAjaran->id}/aktifkan")
        ->assertForbidden();
    $this->actingAs(buatGuru()->user)->postJson('/api/v1/tahun-ajaran', dataTahunAjaran())
        ->assertForbidden();
});
