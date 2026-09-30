<?php

use App\Enums\StatusAkun;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('memberi token dan data user sesuai kontrak A7 saat Kepala Sekolah login dengan password', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'kepsek2026']);

    $response = $this->postJson('/api/v1/auth/staff/login', ['email' => $kepsek->email, 'password' => 'kepsek2026'])
        ->assertOk()
        ->assertJsonPath('message', 'Berhasil masuk.')
        ->assertJsonPath('data.user', [
            'id' => $kepsek->id,
            'name' => $kepsek->name,
            'email' => $kepsek->email,
            'username' => null,
            'role' => 'super_admin',
            'status' => 'aktif',
            'wajib_ganti_password' => false,
            'no_hp' => $kepsek->no_hp,
            'avatar_url' => null,
            'guru' => [
                'id' => $kepsek->guru?->id,
                'bisa_kelola_keuangan' => true,
                'kelas_diampu' => [],
            ],
            'wali_murid' => null,
            'permissions' => ['kelola_keuangan' => true],
        ]);

    expect($kepsek->tokens()->sole()->name)->toBe('web')
        ->and($kepsek->fresh()?->last_login_at)->not->toBeNull();

    $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $kepsek->id);
});

it('menamai token sesuai perangkat', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'kepsek2026']);

    $this->postJson('/api/v1/auth/staff/login', ['email' => $kepsek->email, 'password' => 'kepsek2026', 'perangkat' => 'mobile'])->assertOk();

    expect($kepsek->tokens()->sole()->name)->toBe('mobile');
});

it('mencocokkan email Kepala Sekolah tanpa membedakan huruf besar', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['email' => 'kepsek@tkta8.test', 'password' => 'kepsek2026']);

    $this->postJson('/api/v1/auth/staff/login', ['email' => 'Kepsek@TKTA8.test', 'password' => 'kepsek2026'])->assertOk();
});

it('menolak email atau password yang salah tanpa membedakan penyebabnya', function (Closure $kredensial) {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'rahasia123']);

    $this->postJson('/api/v1/auth/staff/login', $kredensial($kepsek))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.email', ['Email atau password salah.']);
})->with([
    'password salah' => [fn (User $user) => ['email' => $user->email, 'password' => 'bukanpassword1']],
    'email tidak terdaftar' => [fn (User $user) => ['email' => 'tidak.ada@tkta8.test', 'password' => 'rahasia123']],
    'akun wali murid yang punya email' => [fn (User $user) => ['email' => User::factory()->waliMurid()->create(['email' => 'dewi.lestari@wali.tkta8.test', 'password' => 'rahasia123'])->email, 'password' => 'rahasia123']],
    'akun guru yang masih menyimpan password lama' => [fn (User $user) => ['email' => User::factory()->create(['password' => 'rahasia123'])->email, 'password' => 'rahasia123']],
    'akun guru tanpa password' => [fn (User $user) => ['email' => buatGuru()->user->email, 'password' => 'rahasia123']],
]);

it('menolak login Kepala Sekolah nonaktif dengan ACCOUNT_INACTIVE setelah password benar', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'rahasia123', 'status' => StatusAkun::Nonaktif]);

    $this->postJson('/api/v1/auth/staff/login', ['email' => $kepsek->email, 'password' => 'rahasia123'])
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE')
        ->assertJsonPath('message', 'Akun Anda sudah dinonaktifkan. Hubungi pihak sekolah untuk mengaktifkannya kembali.')
        ->assertJsonPath('errors', null);

    $this->postJson('/api/v1/auth/staff/login', ['email' => $kepsek->email, 'password' => 'salahsalah1'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    expect($kepsek->tokens()->count())->toBe(0);
});

it('membalas akun guru di login password persis sama dengan password salah', function () {
    $guru = User::factory()->create(['password' => 'rahasia123']);
    $kepsek = buatKepalaSekolah();

    $akunGuru = $this->postJson('/api/v1/auth/staff/login', ['email' => $guru->email, 'password' => 'rahasia123'])
        ->assertStatus(422);
    $passwordSalah = $this->postJson('/api/v1/auth/staff/login', ['email' => $kepsek->email, 'password' => 'salahsalah1'])
        ->assertStatus(422);

    expect($akunGuru->json())->toBe($passwordSalah->json())
        ->and($guru->tokens()->count())->toBe(0);
});

it('membalas akun wali murid di login staff persis sama dengan password salah', function () {
    User::factory()->waliMurid()->create(['email' => 'dewi.lestari@wali.tkta8.test', 'password' => 'rahasia123']);
    $kepsek = buatKepalaSekolah();

    $akunWali = $this->postJson('/api/v1/auth/staff/login', ['email' => 'dewi.lestari@wali.tkta8.test', 'password' => 'rahasia123'])
        ->assertStatus(422);
    $passwordSalah = $this->postJson('/api/v1/auth/staff/login', ['email' => $kepsek->email, 'password' => 'salahsalah1'])
        ->assertStatus(422);

    expect($akunWali->json())->toBe($passwordSalah->json());
});

it('membatasi login staff 3 kali per menit untuk email dan IP yang sama', function () {
    $this->freezeTime();
    $kepsek = buatKepalaSekolah();

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/staff/login', ['email' => $kepsek->email, 'password' => 'salahsalah1'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/staff/login', ['email' => $kepsek->email, 'password' => 'salahsalah1'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertJsonPath('message', 'Terlalu banyak percobaan. Coba lagi dalam 60 detik.');

    $this->postJson('/api/v1/auth/staff/login', ['email' => 'lain@tkta8.test', 'password' => 'salahsalah1'])->assertStatus(422);
});

it('membatasi login staff 10 kali per menit dari satu IP walau emailnya berbeda', function () {
    $this->freezeTime();

    foreach (range(1, 10) as $ke) {
        $this->postJson('/api/v1/auth/staff/login', ['email' => "guru{$ke}@tkta8.test", 'password' => 'salahsalah1'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/staff/login', ['email' => 'guru11@tkta8.test', 'password' => 'salahsalah1'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
        ->postJson('/api/v1/auth/staff/login', ['email' => 'guru11@tkta8.test', 'password' => 'salahsalah1'])
        ->assertStatus(422);
});

it('menyimpan password sebagai hash', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'rahasia123']);

    expect($kepsek->fresh()?->password)->not->toBe('rahasia123')
        ->and(Hash::check('rahasia123', (string) $kepsek->fresh()?->password))->toBeTrue();
});
