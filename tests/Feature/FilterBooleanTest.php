<?php

use App\Models\GaleriAlbum;
use App\Models\JenisTagihan;
use App\Models\Pengumuman;

/**
 * Filter boolean didokumentasikan bertipe boolean di OpenAPI, jadi `true`/`false` harus diterima sama seperti
 * `1`/`0`. Setiap endpoint punya satu data yang cocok dengan nilai benar dan satu dengan nilai salah.
 */
beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();

    $this->kepsek->notify(notifikasiPendaftarBaru(7, 'Fitri Handayani'));
    $this->kepsek->notify(notifikasiPendaftarBaru(8, 'Ahmad Fauzi'));
    $this->kepsek->notifications()->latest('id')->first()?->markAsRead();

    GaleriAlbum::factory()->create(['is_publik' => true]);
    GaleriAlbum::factory()->create(['is_publik' => false]);
    JenisTagihan::factory()->create(['is_aktif' => true]);
    JenisTagihan::factory()->create(['is_aktif' => false]);
    Pengumuman::factory()->for($this->kepsek, 'penulis')->create();
    Pengumuman::factory()->for($this->kepsek, 'penulis')->draft()->create();
});

it('menerima true, false, 1, dan 0 pada filter boolean', function (string $path, string $filter, string $nilai) {
    $semua = $this->actingAs($this->kepsek)->getJson("/api/v1/{$path}")->assertOk()->json('meta.total');

    $this->getJson("/api/v1/{$path}?filter[{$filter}]={$nilai}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
    $sebaliknya = in_array($nilai, ['true', '1', 'TRUE'], true) ? 'false' : 'true';
    $this->getJson("/api/v1/{$path}?filter[{$filter}]={$sebaliknya}")
        ->assertJsonPath('meta.total', $semua - 1);
})->with([
    'notifikasi' => ['notifikasi', 'dibaca'],
    'galeri' => ['galeri-album', 'is_publik'],
    'pengumuman' => ['pengumuman', 'terbit'],
])->with(['true', 'false', '1', '0', 'TRUE']);

it('menerima true dan false pada filter boolean jenis tagihan', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/jenis-tagihan?filter[is_aktif]=true')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/jenis-tagihan?filter[is_aktif]=false')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/jenis-tagihan?filter[is_aktif]=0')->assertOk()->assertJsonCount(1, 'data');
});

it('tetap menolak nilai filter boolean selain true, false, 1, dan 0', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/notifikasi?filter[dibaca]=ya')
        ->assertStatus(422)
        ->assertJsonValidationErrors('filter.dibaca');
});
