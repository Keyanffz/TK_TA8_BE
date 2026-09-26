<?php

use App\Models\ElemenPenilaian;
use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Models\User;
use Database\Seeders\ElemenPenilaianSeeder;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $this->seed(ElemenPenilaianSeeder::class);
});

it('menampilkan elemen penilaian urut ke Kepala Sekolah dan guru, tidak ke wali', function () {
    ElemenPenilaian::query()->where('kode', 'JD')->update(['is_aktif' => false]);

    $this->actingAs(buatGuru()->user)->getJson('/api/v1/elemen-penilaian')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.kode', 'NAB')
        ->assertJsonPath('data.1.kode', 'JD')
        ->assertJsonPath('data.1.is_aktif', false)
        ->assertJsonPath('data.2.nama', 'Dasar-dasar Literasi, Matematika, Sains, Teknologi, Rekayasa & Seni');

    $this->actingAs(User::factory()->waliMurid()->create())->getJson('/api/v1/elemen-penilaian')->assertForbidden();
});

it('menambah elemen di urutan terakhir dengan kode huruf besar', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/elemen-penilaian', ['kode' => 'kokurikuler', 'nama' => 'Projek Penguatan Profil Pelajar Pancasila'])
        ->assertCreated()
        ->assertJsonPath('data.kode', 'KOKURIKULER')
        ->assertJsonPath('data.urutan', 4)
        ->assertJsonPath('data.is_aktif', true);
});

it('menolak kode elemen yang sudah dipakai atau berisi spasi', function (string $kode) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/elemen-penilaian', ['kode' => $kode, 'nama' => 'Elemen baru'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['kode']);
})->with(['nab', 'JATI DIRI']);

it('mengubah dan menonaktifkan elemen', function () {
    $elemen = ElemenPenilaian::query()->where('kode', 'JD')->sole();

    $this->actingAs($this->kepsek)->putJson("/api/v1/elemen-penilaian/{$elemen->id}", ['kode' => 'JD', 'nama' => 'Jati Diri', 'is_aktif' => false])
        ->assertOk()
        ->assertJsonPath('data.is_aktif', false)
        ->assertJsonPath('data.urutan', 2);
});

it('menolak menghapus elemen yang sudah dipakai di rapor, dan menghapus yang belum', function () {
    $dipakai = ElemenPenilaian::query()->where('kode', 'NAB')->sole();
    RaporDetail::factory()->for(Rapor::factory())->for($dipakai)->create();
    $belum = ElemenPenilaian::query()->where('kode', 'JD')->sole();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/elemen-penilaian/{$dipakai->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Elemen Nilai Agama & Budi Pekerti sudah dipakai di rapor sehingga tidak bisa dihapus. Nonaktifkan elemen ini supaya tidak muncul di rapor baru.');

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/elemen-penilaian/{$belum->id}")->assertOk();
    expect(ElemenPenilaian::query()->orderBy('urutan')->pluck('kode')->all())->toBe(['NAB', 'LITERASI_STEAM']);
});

it('hanya Kepala Sekolah yang bisa mengelola elemen penilaian', function () {
    $guru = buatGuru()->user;
    $elemen = ElemenPenilaian::query()->first();

    $this->actingAs($guru)->postJson('/api/v1/elemen-penilaian', ['kode' => 'BARU', 'nama' => 'Baru'])->assertForbidden();
    $this->actingAs($guru)->putJson("/api/v1/elemen-penilaian/{$elemen->id}", ['kode' => 'BARU', 'nama' => 'Baru'])->assertForbidden();
    $this->actingAs($guru)->deleteJson("/api/v1/elemen-penilaian/{$elemen->id}")->assertForbidden();
});
