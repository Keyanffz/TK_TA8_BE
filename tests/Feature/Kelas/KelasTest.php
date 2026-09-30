<?php

use App\Enums\StatusAkun;
use App\Enums\StatusKelasMurid;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\User;

/**
 * Tahun ajaran aktif: TK A1 (wali kelas Bu Aini, pendamping Bu Rina) dan TK B1 (wali kelas Bu Sri).
 * Tahun ajaran lalu: TK A1 lama yang juga diampu Bu Aini.
 */
beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $this->aktif = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->lalu = TahunAjaran::factory()->create(['nama' => '2025/2026']);

    $this->buAini = buatGuru();
    $this->buRina = buatGuru();
    $this->buSri = buatGuru();

    $this->kelasA1 = Kelas::factory()->for($this->aktif)->create([
        'nama' => 'TK A1', 'tingkat' => 'A', 'wali_kelas_id' => $this->buAini->id, 'guru_pendamping_id' => $this->buRina->id,
    ]);
    $this->kelasB1 = Kelas::factory()->for($this->aktif)->create(['nama' => 'TK B1', 'tingkat' => 'B', 'wali_kelas_id' => $this->buSri->id]);
    $this->kelasLama = Kelas::factory()->for($this->lalu)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
});

it('menampilkan semua kelas beserta jumlah murid aktif ke Kepala Sekolah', function () {
    $this->kelasA1->murid()->attach(Murid::factory()->count(2)->create());
    $this->kelasA1->murid()->attach(Murid::factory()->create(), ['status' => StatusKelasMurid::Keluar]);

    $this->actingAs($this->kepsek)->getJson('/api/v1/kelas?filter[tahun_ajaran_id]='.$this->aktif->id)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.nama', 'TK A1')
        ->assertJsonPath('data.0.jumlah_murid', 2)
        ->assertJsonPath('data.0.wali_kelas.id', $this->buAini->id)
        ->assertJsonPath('data.0.wali_kelas.nama', $this->buAini->user->name)
        ->assertJsonPath('data.0.guru_pendamping.id', $this->buRina->id)
        ->assertJsonPath('data.0.tahun_ajaran', ['id' => $this->aktif->id, 'nama' => '2026/2027', 'is_aktif' => true])
        ->assertJsonPath('data.1.guru_pendamping', null)
        ->assertJsonMissingPath('data.0.murid');

    $this->getJson('/api/v1/kelas')->assertJsonPath('meta.total', 3);
});

it('menampilkan ke guru hanya kelas yang dia ampu di tahun ajaran aktif', function (string $guru, array $kelas) {
    $response = $this->actingAs($this->{$guru}->user)->getJson('/api/v1/kelas')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe(array_map(fn (string $nama) => $this->{$nama}->id, $kelas));
})->with([
    'wali kelas' => ['buAini', ['kelasA1']],
    'guru pendamping' => ['buRina', ['kelasA1']],
    'wali kelas lain' => ['buSri', ['kelasB1']],
]);

it('membalas 404 saat guru membuka kelas yang tidak dia ampu', function (string $kelas) {
    $this->actingAs($this->buSri->user)->getJson('/api/v1/kelas/'.$this->{$kelas}->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('message', 'Data tidak ditemukan.');
})->with(['kelas lain di tahun ajaran aktif' => ['kelasA1'], 'kelas tahun ajaran lalu' => ['kelasLama']]);

it('membalas 404 saat guru membuka kelas yang dulu dia ampu di tahun ajaran lalu', function () {
    $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelasLama->id}")
        ->assertNotFound();
});

it('menampilkan detail kelas beserta murid ke guru pengampu', function () {
    $bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri']);
    $this->kelasA1->murid()->attach([$bima->id, $aisyah->id]);

    $this->actingAs($this->buRina->user)->getJson("/api/v1/kelas/{$this->kelasA1->id}")
        ->assertOk()
        ->assertJsonPath('data.jumlah_murid', 2)
        ->assertJsonPath('data.murid.0.nama_lengkap', 'Aisyah Putri')
        ->assertJsonPath('data.murid.0.status_kelas', 'aktif')
        ->assertJsonPath('data.murid.1.id', $bima->id);
});

it('menutup menu kelas untuk wali murid', function () {
    $wali = User::factory()->waliMurid()->create();

    $this->actingAs($wali)->getJson('/api/v1/kelas')->assertForbidden();
    $this->actingAs($wali)->getJson("/api/v1/kelas/{$this->kelasA1->id}")->assertForbidden();
});

it('membuat kelas baru', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/kelas', [
        'tahun_ajaran_id' => $this->aktif->id,
        'nama' => 'TK A2',
        'tingkat' => 'A',
        'wali_kelas_id' => $this->buRina->id,
        'kapasitas' => 18,
    ])
        ->assertCreated()
        ->assertJsonPath('data.nama', 'TK A2')
        ->assertJsonPath('data.kapasitas', 18)
        ->assertJsonPath('data.jumlah_murid', 0)
        ->assertJsonPath('data.wali_kelas.id', $this->buRina->id)
        ->assertJsonPath('data.murid', []);
});

it('membolehkan nama kelas yang sama di tahun ajaran berbeda', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/kelas', [
        'tahun_ajaran_id' => TahunAjaran::factory()->create(['nama' => '2027/2028'])->id,
        'nama' => 'TK A1',
        'tingkat' => 'A',
    ])
        ->assertCreated()
        ->assertJsonPath('data.kapasitas', 20);
});

it('memvalidasi isian kelas', function (Closure $data, string $field) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/kelas', [
        'tahun_ajaran_id' => $this->aktif->id,
        'nama' => 'TK B2',
        'tingkat' => 'B',
        ...$data->call($this),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'nama sudah dipakai di tahun ajaran yang sama' => [fn () => ['nama' => 'TK A1'], 'nama'],
    'tingkat tidak dikenal' => [fn () => ['tingkat' => 'C'], 'tingkat'],
    'wali kelas nonaktif' => [fn () => ['wali_kelas_id' => buatGuru(StatusAkun::Nonaktif)->id], 'wali_kelas_id'],
    'pendamping sama dengan wali kelas' => [fn () => ['wali_kelas_id' => $this->buSri->id, 'guru_pendamping_id' => $this->buSri->id], 'guru_pendamping_id'],
    'kapasitas nol' => [fn () => ['kapasitas' => 0], 'kapasitas'],
]);

it('menolak kapasitas di bawah jumlah murid aktif', function () {
    $this->kelasB1->murid()->attach(Murid::factory()->count(3)->create());

    $this->actingAs($this->kepsek)->putJson("/api/v1/kelas/{$this->kelasB1->id}", [
        'tahun_ajaran_id' => $this->aktif->id,
        'nama' => 'TK B1',
        'tingkat' => 'B',
        'kapasitas' => 2,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect($this->kelasB1->fresh()?->kapasitas)->toBe(20);
});

it('menolak memindah tahun ajaran kelas yang sudah berisi murid', function () {
    $this->kelasB1->murid()->attach(Murid::factory()->create());

    $this->actingAs($this->kepsek)->putJson("/api/v1/kelas/{$this->kelasB1->id}", [
        'tahun_ajaran_id' => TahunAjaran::factory()->create(['nama' => '2027/2028'])->id,
        'nama' => 'TK B1',
        'tingkat' => 'B',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('memperbarui wali kelas dan guru pendamping', function () {
    $this->actingAs($this->kepsek)->putJson("/api/v1/kelas/{$this->kelasB1->id}", [
        'tahun_ajaran_id' => $this->aktif->id,
        'nama' => 'TK B1',
        'tingkat' => 'B',
        'wali_kelas_id' => $this->buRina->id,
        'guru_pendamping_id' => $this->buSri->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.wali_kelas.id', $this->buRina->id)
        ->assertJsonPath('data.guru_pendamping.id', $this->buSri->id);

    $this->actingAs($this->buRina->user)->getJson("/api/v1/kelas/{$this->kelasB1->id}")->assertOk();
});

it('menghapus kelas kosong dan menolak kelas yang sudah berisi murid', function () {
    $this->kelasB1->murid()->attach(Murid::factory()->create());
    $kosong = Kelas::factory()->for($this->aktif)->create();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/kelas/{$kosong->id}")->assertOk();
    $this->deleteJson("/api/v1/kelas/{$this->kelasB1->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Kelas::query()->find($kosong->id))->toBeNull()
        ->and(Kelas::query()->find($this->kelasB1->id))->not->toBeNull();
});

it('hanya Kepala Sekolah yang bisa membuat, mengubah, dan menghapus kelas', function () {
    $guru = $this->buAini->user;

    $this->actingAs($guru)->postJson('/api/v1/kelas', ['nama' => 'TK A3'])->assertForbidden();
    $this->actingAs($guru)->putJson("/api/v1/kelas/{$this->kelasA1->id}", ['nama' => 'TK A3'])->assertForbidden();
    $this->actingAs($guru)->deleteJson("/api/v1/kelas/{$this->kelasA1->id}")->assertForbidden();
});
