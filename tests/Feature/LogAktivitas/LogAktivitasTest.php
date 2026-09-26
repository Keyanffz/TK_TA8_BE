<?php

use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
});

it('menampilkan log aktivitas terbaru lebih dulu beserta pelaku dan subjek', function () {
    $tagihan = Tagihan::factory()->create(['kode' => 'INV-202610-00001']);
    Carbon::setTestNow('2026-10-01 08:00:00');
    activity('tagihan')->event('generate')->withProperties(['periode' => '2026-10'])->log('Generate tagihan bulanan Oktober 2026');
    Carbon::setTestNow('2026-10-02 09:00:00');
    activity('tagihan')->causedBy($this->kepsek)->performedOn($tagihan)->event('dibatalkan')->withProperties(['alasan' => 'Salah jenis.'])->log('Membatalkan tagihan INV-202610-00001');

    $this->actingAs($this->kepsek)->getJson('/api/v1/log-aktivitas')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.jenis', 'tagihan')
        ->assertJsonPath('data.0.event', 'dibatalkan')
        ->assertJsonPath('data.0.deskripsi', 'Membatalkan tagihan INV-202610-00001')
        ->assertJsonPath('data.0.pelaku', ['id' => $this->kepsek->id, 'nama' => $this->kepsek->name, 'role' => 'super_admin'])
        ->assertJsonPath('data.0.subjek', ['tipe' => 'tagihan', 'id' => $tagihan->id])
        ->assertJsonPath('data.0.properti', ['alasan' => 'Salah jenis.'])
        ->assertJsonPath('data.1.pelaku', null)
        ->assertJsonPath('data.1.subjek', null);
});

it('memfilter log aktivitas menurut pelaku, jenis, dan tanggal', function () {
    $guru = buatGuru()->user;
    Carbon::setTestNow('2026-10-01 08:00:00');
    activity('pembayaran')->causedBy($guru)->event('diterima')->log('Menerima pembayaran');
    Carbon::setTestNow('2026-10-02 08:00:00');
    activity('rapor')->causedBy($this->kepsek)->event('terbit')->log('Menerbitkan rapor');

    $this->actingAs($this->kepsek)->getJson("/api/v1/log-aktivitas?filter[user_id]={$guru->id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.jenis', 'pembayaran');
    $this->actingAs($this->kepsek)->getJson('/api/v1/log-aktivitas?filter[jenis]=rapor')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event', 'terbit');
    $this->actingAs($this->kepsek)->getJson('/api/v1/log-aktivitas?filter[tanggal]=2026-10-01')->assertJsonPath('meta.total', 1);
    $this->actingAs($this->kepsek)->getJson('/api/v1/log-aktivitas?filter[jenis]=lainnya')->assertStatus(422);
});

it('hanya Kepala Sekolah yang bisa melihat log aktivitas', function () {
    $this->actingAs(buatGuru()->user)->getJson('/api/v1/log-aktivitas')->assertForbidden();
    $this->actingAs(User::factory()->waliMurid()->create())->getJson('/api/v1/log-aktivitas')->assertForbidden();
});
