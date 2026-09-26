<?php

use App\Models\User;

it('membatasi API umum 120 request per menit per pengguna', function () {
    $wali = User::factory()->waliMurid()->create();
    $lain = User::factory()->waliMurid()->create();

    foreach (range(1, 120) as $ke) {
        $this->actingAs($wali)->getJson('/api/v1/notifikasi/belum-dibaca')->assertOk();
    }

    $this->actingAs($wali)->getJson('/api/v1/notifikasi/belum-dibaca')
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');
    $this->actingAs($lain)->getJson('/api/v1/notifikasi/belum-dibaca')->assertOk();
});

it('membatasi endpoint publik 120 request per menit per IP, tanpa membatasi health check', function () {
    foreach (range(1, 120) as $ke) {
        $this->getJson('/api/v1/public/guru')->assertOk();
    }

    $this->getJson('/api/v1/public/guru')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->getJson('/api/v1/public/guru')->assertOk();
    $this->getJson('/api/v1/health')->assertOk();
});
