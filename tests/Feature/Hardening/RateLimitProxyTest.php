<?php

/**
 * FE memakai pola BFF: request tanpa login datang dari IP server Next.js (10.0.0.5 di test ini) dengan IP asli
 * klien di X-Forwarded-For. Semua limiter berbasis IP harus memakai IP klien itu, tetapi hanya kalau proxy-nya
 * terdaftar di TRUSTED_PROXIES.
 */
const IP_SERVER_FE = '10.0.0.5';

function lewatFe(object $test, string $ipKlien): object
{
    return $test->withServerVariables(['REMOTE_ADDR' => IP_SERVER_FE])->withHeader('X-Forwarded-For', $ipKlien);
}

beforeEach(function () {
    config(['trustedproxy.proxies' => IP_SERVER_FE]);
});

it('menghitung limiter api per IP klien asli untuk request lewat server FE', function () {
    foreach (range(1, 120) as $_) {
        lewatFe($this, '203.0.113.10')->getJson('/api/v1/public/guru')->assertOk();
    }

    lewatFe($this, '203.0.113.10')->getJson('/api/v1/public/guru')->assertTooManyRequests();
    lewatFe($this, '203.0.113.20')->getJson('/api/v1/public/guru')->assertOk();
});

it('menghitung limiter login per email dan IP klien asli untuk request lewat server FE', function () {
    $guru = buatGuru();
    $data = ['email' => $guru->user->email, 'password' => 'salahsalah1'];

    foreach (range(1, 5) as $_) {
        lewatFe($this, '203.0.113.10')->postJson('/api/v1/auth/login', $data)->assertStatus(422);
    }

    lewatFe($this, '203.0.113.10')->postJson('/api/v1/auth/login', $data)->assertTooManyRequests();
    lewatFe($this, '203.0.113.20')->postJson('/api/v1/auth/login', $data)->assertStatus(422);
});

it('menghitung limiter login wali per NIS dan IP klien asli untuk request lewat server FE', function () {
    $data = ['username' => 'TA20260001', 'password' => 'salahsalah1'];

    foreach (range(1, 5) as $_) {
        lewatFe($this, '203.0.113.10')->postJson('/api/v1/auth/login-wali', $data)->assertStatus(422);
    }

    lewatFe($this, '203.0.113.10')->postJson('/api/v1/auth/login-wali', $data)->assertTooManyRequests();
    lewatFe($this, '203.0.113.20')->postJson('/api/v1/auth/login-wali', $data)->assertStatus(422);
});

it('mengabaikan X-Forwarded-For dari sumber yang tidak ada di TRUSTED_PROXIES', function () {
    config(['trustedproxy.proxies' => null]);
    $guru = buatGuru();
    $data = ['email' => $guru->user->email, 'password' => 'salahsalah1'];

    foreach (range(1, 5) as $ke) {
        lewatFe($this, "203.0.113.{$ke}")->postJson('/api/v1/auth/login', $data)->assertStatus(422);
    }

    lewatFe($this, '203.0.113.99')->postJson('/api/v1/auth/login', $data)->assertTooManyRequests();
});
