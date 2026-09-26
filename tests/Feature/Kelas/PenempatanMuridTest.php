<?php

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\Rapor;
use App\Models\TahunAjaran;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $this->aktif = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->kelasA1 = Kelas::factory()->for($this->aktif)->create(['nama' => 'TK A1', 'kapasitas' => 3]);
    $this->kelasA2 = Kelas::factory()->for($this->aktif)->create(['nama' => 'TK A2']);
});

it('menempatkan murid ke kelas', function () {
    $murid = Murid::factory()->count(2)->create();

    $this->actingAs($this->kepsek)->postJson("/api/v1/kelas/{$this->kelasA1->id}/murid", ['murid_ids' => $murid->modelKeys()])
        ->assertOk()
        ->assertJsonPath('message', '2 murid ditempatkan di TK A1.')
        ->assertJsonPath('data.jumlah_murid', 2)
        ->assertJsonCount(2, 'data.murid');

    expect(KelasMurid::query()->where('kelas_id', $this->kelasA1->id)->where('status', StatusKelasMurid::Aktif)->count())->toBe(2);
});

it('menolak penempatan yang melebihi kapasitas kelas', function () {
    $this->kelasA1->murid()->attach(Murid::factory()->count(2)->create());
    $baru = Murid::factory()->count(2)->create();

    $this->actingAs($this->kepsek)->postJson("/api/v1/kelas/{$this->kelasA1->id}/murid", ['murid_ids' => $baru->modelKeys()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Kelas TK A1 hanya punya sisa 1 tempat (kapasitas 3), sedangkan yang akan ditempatkan 2 murid.');

    expect($this->kelasA1->muridAktif()->count())->toBe(2);
});

it('tidak menghitung murid yang sudah keluar dari kelas ke kapasitas', function () {
    $this->kelasA1->murid()->attach(Murid::factory()->count(3)->create(), ['status' => StatusKelasMurid::Keluar]);

    $this->actingAs($this->kepsek)->postJson("/api/v1/kelas/{$this->kelasA1->id}/murid", ['murid_ids' => [Murid::factory()->create()->id]])
        ->assertOk();
});

it('menolak murid yang sudah punya kelas di tahun ajaran yang sama', function () {
    $bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $this->kelasA2->murid()->attach($bima);

    $this->actingAs($this->kepsek)->postJson("/api/v1/kelas/{$this->kelasA1->id}/murid", ['murid_ids' => [$bima->id]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Murid berikut sudah punya kelas di tahun ajaran ini: Bima Saputra (TK A2).');
});

it('membolehkan murid punya kelas di tahun ajaran lain', function () {
    $bima = Murid::factory()->create();
    Kelas::factory()->for(TahunAjaran::factory()->create(['nama' => '2025/2026']))->create()->murid()->attach($bima);

    $this->actingAs($this->kepsek)->postJson("/api/v1/kelas/{$this->kelasA1->id}/murid", ['murid_ids' => [$bima->id]])
        ->assertOk();
});

it('menolak menempatkan murid yang tidak aktif', function () {
    $lulus = Murid::factory()->create(['status' => StatusMurid::Lulus, 'tanggal_keluar' => '2026-06-20']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/kelas/{$this->kelasA1->id}/murid", ['murid_ids' => [$lulus->id]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('memvalidasi daftar murid yang ditempatkan', function (Closure $muridIds) {
    $this->actingAs($this->kepsek)->postJson("/api/v1/kelas/{$this->kelasA1->id}/murid", ['murid_ids' => $muridIds()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
})->with([
    'kosong' => [fn () => []],
    'murid tidak ada' => [fn () => [999999]],
    'murid ganda' => [function () {
        $id = Murid::factory()->create()->id;

        return [$id, $id];
    }],
    'murid terhapus' => [function () {
        $murid = Murid::factory()->create();
        $murid->delete();

        return [$murid->id];
    }],
]);

it('mengeluarkan murid dari kelas', function () {
    $bima = Murid::factory()->create();
    $this->kelasA1->murid()->attach($bima);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/kelas/{$this->kelasA1->id}/murid/{$bima->id}")
        ->assertOk();

    expect($this->kelasA1->murid()->count())->toBe(0);
});

it('menolak mengeluarkan murid yang sudah punya rapor di kelas itu', function () {
    $bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $this->kelasA1->murid()->attach($bima);
    Rapor::factory()->for($bima)->for($this->kelasA1)->for($this->aktif)->create();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/kelas/{$this->kelasA1->id}/murid/{$bima->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Bima Saputra sudah punya rapor di TK A1 sehingga tidak bisa dikeluarkan dari kelas ini. Kalau murid keluar sekolah, ubah statusnya di data murid.');

    expect($this->kelasA1->murid()->count())->toBe(1);
});

it('tetap mengeluarkan murid yang rapornya ada di kelas lain', function () {
    $bima = Murid::factory()->create();
    $this->kelasA1->murid()->attach($bima);
    $lama = Kelas::factory()->for(TahunAjaran::factory()->create(['nama' => '2025/2026']))->create();
    Rapor::factory()->for($bima)->for($lama, 'kelas')->for($lama->tahunAjaran)->create();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/kelas/{$this->kelasA1->id}/murid/{$bima->id}")
        ->assertOk();

    expect($this->kelasA1->murid()->count())->toBe(0);
});

it('membalas 404 saat mengeluarkan murid yang tidak ada di kelas itu', function () {
    $bima = Murid::factory()->create();
    $this->kelasA2->murid()->attach($bima);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/kelas/{$this->kelasA1->id}/murid/{$bima->id}")
        ->assertNotFound();

    expect($this->kelasA2->murid()->count())->toBe(1);
});

it('hanya Kepala Sekolah yang bisa menempatkan dan mengeluarkan murid', function () {
    $guru = buatGuru();
    $this->kelasA1->update(['wali_kelas_id' => $guru->id]);
    $bima = Murid::factory()->create();

    $this->actingAs($guru->user)->postJson("/api/v1/kelas/{$this->kelasA1->id}/murid", ['murid_ids' => [$bima->id]])->assertForbidden();
    $this->actingAs($guru->user)->deleteJson("/api/v1/kelas/{$this->kelasA1->id}/murid/{$bima->id}")->assertForbidden();
});
