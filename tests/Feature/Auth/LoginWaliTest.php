<?php

use App\Enums\Hubungan;
use App\Enums\StatusAkun;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->murid = Murid::factory()->create(['nis' => 'TA20260007', 'nama_panggilan' => 'Kirana', 'tanggal_lahir' => '2021-03-15']);
    $this->user = User::factory()->waliMurid()->wajibGantiPassword()->create([
        'name' => 'Wali Kirana',
        'email' => null,
        'username' => 'TA20260007',
        'password' => '15032021',
        'no_hp' => null,
    ]);
    $this->wali = WaliMurid::factory()->for($this->user)->profilBelumLengkap()->create();
    $this->murid->waliMurid()->attach($this->wali, ['hubungan' => Hubungan::Wali, 'is_kontak_utama' => true]);
});

it('memberi token dan data user saat wali login dengan NIS dan password awal', function () {
    $response = $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260007', 'password' => '15032021', 'perangkat' => 'mobile'])
        ->assertOk()
        ->assertJsonPath('message', 'Berhasil masuk.')
        ->assertJsonPath('data.user.id', $this->user->id)
        ->assertJsonPath('data.user.email', null)
        ->assertJsonPath('data.user.username', 'TA20260007')
        ->assertJsonPath('data.user.role', 'wali_murid')
        ->assertJsonPath('data.user.wajib_ganti_password', true)
        ->assertJsonPath('data.user.wali_murid.profil_lengkap', false)
        ->assertJsonPath('data.user.wali_murid.anak.0.nama_panggilan', 'Kirana');

    expect($response->json('data.token'))->toBeString()
        ->and($this->user->tokens()->sole()->name)->toBe('mobile')
        ->and($this->user->fresh()?->last_login_at)->not->toBeNull();
});

it('menerima NIS yang diketik dengan huruf kecil atau spasi', function () {
    $this->postJson('/api/v1/auth/wali/login', ['username' => ' ta2026 0007 ', 'password' => '15032021'])
        ->assertOk()
        ->assertJsonPath('data.user.username', 'TA20260007');
});

it('menolak NIS atau password yang salah tanpa membedakan penyebabnya', function (Closure $kredensial) {
    $this->postJson('/api/v1/auth/wali/login', $kredensial())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.username', ['NIS atau password salah.']);
})->with([
    'password salah' => [fn () => ['username' => 'TA20260007', 'password' => '16032021']],
    'NIS tidak terdaftar' => [fn () => ['username' => 'TA20269999', 'password' => '15032021']],
    'akun guru' => [function () {
        $guru = buatGuru();
        $guru->user->update(['username' => 'TA20260099', 'password' => 'rahasia123']);

        return ['username' => 'TA20260099', 'password' => 'rahasia123'];
    }],
]);

it('menolak akun wali nonaktif hanya setelah password benar', function () {
    $this->user->update(['status' => StatusAkun::Nonaktif]);

    $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260007', 'password' => 'salahSalah1'])
        ->assertStatus(422);

    $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260007', 'password' => '15032021'])
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');

    expect($this->user->tokens()->count())->toBe(0);
});

it('membalas akun guru di login wali persis sama dengan password salah', function () {
    $guru = buatGuru();
    $guru->user->update(['username' => 'TA20260099', 'password' => 'rahasia123']);

    $akunGuru = $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260099', 'password' => 'rahasia123'])
        ->assertStatus(422);
    $passwordSalah = $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260007', 'password' => '16032021'])
        ->assertStatus(422);

    expect($akunGuru->json())->toBe($passwordSalah->json());
});

it('membatasi login wali 5 kali per menit untuk NIS dan IP yang sama', function () {
    $this->freezeTime();

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260007', 'password' => 'salahSalah1'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/wali/login', ['username' => 'ta20260007', 'password' => '15032021'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertJsonPath('message', 'Terlalu banyak percobaan. Coba lagi dalam 60 detik.');

    $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260008', 'password' => 'salahSalah1'])->assertStatus(422);
});

it('membatasi login wali 20 kali per menit dari satu IP walau NIS-nya berbeda', function () {
    $this->freezeTime();

    foreach (range(1, 20) as $ke) {
        $this->postJson('/api/v1/auth/wali/login', ['username' => sprintf('TA2026%04d', 100 + $ke), 'password' => 'salahSalah1'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260007', 'password' => '15032021'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
        ->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260007', 'password' => '15032021'])
        ->assertOk();
});

it('membatasi akun yang wajib ganti password ke /auth/me, ganti password, dan logout', function () {
    $token = $this->user->createToken('web')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.wajib_ganti_password', true);

    foreach ([['getJson', '/api/v1/dashboard'], ['getJson', '/api/v1/wali/anak'], ['putJson', '/api/v1/auth/profil'], ['getJson', '/api/v1/notifikasi']] as [$metode, $url]) {
        $this->withToken($token)->{$metode}($url)
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_WAJIB_DIGANTI')
            ->assertJsonPath('message', 'Ganti password awal Anda terlebih dahulu sebelum memakai fitur lain.');
    }

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
});

it('membuka semua fitur setelah wali mengganti password awal', function () {
    $token = $this->user->createToken('web')->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/auth/password', [
        'current_password' => '15032021',
        'password' => 'kiranaCeria21',
        'password_confirmation' => 'kiranaCeria21',
    ])->assertOk();

    $user = $this->user->fresh();
    expect($user?->wajib_ganti_password)->toBeFalse()
        ->and(Hash::check('kiranaCeria21', (string) $user?->password))->toBeTrue();

    $this->withToken($token)->getJson('/api/v1/wali/anak')->assertOk();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertJsonPath('data.wajib_ganti_password', false);
});

it('menolak password baru yang sama dengan tanggal lahir salah satu anak', function () {
    $adik = Murid::factory()->create(['tanggal_lahir' => '2023-01-09']);
    $adik->waliMurid()->attach($this->wali, ['hubungan' => Hubungan::Wali, 'is_kontak_utama' => true]);
    $this->user->update(['password' => 'sementara12']);

    $response = $this->actingAs($this->user)->putJson('/api/v1/auth/password', [
        'current_password' => 'sementara12',
        'password' => '09012023',
        'password_confirmation' => '09012023',
    ])->assertStatus(422);

    expect($response->json('errors.password'))->toContain('Password baru tidak boleh sama dengan tanggal lahir anak.')
        ->and($this->user->fresh()?->wajib_ganti_password)->toBeTrue();
});
