<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();

    Carbon::setTestNow('2026-10-01 08:00:00');
    $this->kepsek->notify(notifikasiPendaftarBaru(7, 'Fitri Handayani'));
    Carbon::setTestNow('2026-10-02 09:30:00');
    $this->kepsek->notify(notifikasiPendaftarBaru(8, 'Ahmad Fauzi'));
});

it('menampilkan notifikasi pengguna dalam bentuk A7, terbaru lebih dulu', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/notifikasi')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.jenis', 'pendaftaran_baru')
        ->assertJsonPath('data.0.judul', 'Pendaftar PPDB baru')
        ->assertJsonPath('data.0.pesan', 'Ahmad Fauzi didaftarkan ke Kelompok A (PPDB-2027-0008). Periksa dokumennya.')
        ->assertJsonPath('data.0.url', '/mudarris/ppdb/8')
        ->assertJsonPath('data.0.dibaca_at', null)
        ->assertJsonPath('data.0.created_at', '2026-10-02T09:30:00+07:00')
        ->assertJsonPath('data.1.url', '/mudarris/ppdb/7');
});

it('tidak menampilkan notifikasi milik pengguna lain', function () {
    $guru = buatGuru();

    $this->actingAs($guru->user)->getJson('/api/v1/notifikasi')->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($guru->user)->getJson('/api/v1/notifikasi/belum-dibaca')->assertJsonPath('data.jumlah', 0);
});

it('menghitung, menandai satu, lalu menandai semua notifikasi sudah dibaca', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/notifikasi/belum-dibaca')->assertOk()->assertJsonPath('data', ['jumlah' => 2]);

    $id = $this->kepsek->notifications()->latest()->value('id');
    $this->actingAs($this->kepsek)->postJson("/api/v1/notifikasi/{$id}/baca")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.dibaca_at', '2026-10-02T09:30:00+07:00');

    $this->actingAs($this->kepsek)->getJson('/api/v1/notifikasi/belum-dibaca')->assertJsonPath('data.jumlah', 1);
    $this->actingAs($this->kepsek)->getJson('/api/v1/notifikasi?filter[dibaca]=0')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.url', '/mudarris/ppdb/7');

    $this->actingAs($this->kepsek)->postJson('/api/v1/notifikasi/baca-semua')
        ->assertOk()
        ->assertJsonPath('data.jumlah', 1)
        ->assertJsonPath('message', '1 notifikasi ditandai sudah dibaca.');
    $this->actingAs($this->kepsek)->getJson('/api/v1/notifikasi/belum-dibaca')->assertJsonPath('data.jumlah', 0);
});

it('membalas 404 saat menandai notifikasi milik pengguna lain atau id yang bukan UUID', function () {
    $id = $this->kepsek->notifications()->value('id');
    $wali = User::factory()->waliMurid()->create();

    $this->actingAs($wali)->postJson("/api/v1/notifikasi/{$id}/baca")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    $this->actingAs($this->kepsek)->postJson('/api/v1/notifikasi/'.Str::uuid().'/baca')->assertNotFound();
    $this->actingAs($this->kepsek)->postJson('/api/v1/notifikasi/12/baca')->assertNotFound();

    expect($this->kepsek->unreadNotifications()->count())->toBe(2);
});

it('mewajibkan login untuk notifikasi', function () {
    $this->getJson('/api/v1/notifikasi')->assertUnauthorized();
});
