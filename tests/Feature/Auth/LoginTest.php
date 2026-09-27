<?php

use App\Enums\StatusAkun;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('memberi token dan data user sesuai kontrak A7 saat guru login', function () {
    $guru = buatGuru();
    $guru->user->update(['password' => 'rahasia123']);
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create(['nama' => 'TK A1', 'wali_kelas_id' => $guru->id]);
    Kelas::factory()->create(['nama' => 'TK A9', 'wali_kelas_id' => $guru->id]);

    $response = $this->postJson('/api/v1/auth/login', ['email' => $guru->user->email, 'password' => 'rahasia123'])
        ->assertOk()
        ->assertJsonPath('message', 'Berhasil masuk.')
        ->assertJsonPath('data.user', [
            'id' => $guru->user_id,
            'name' => $guru->user->name,
            'email' => $guru->user->email,
            'username' => null,
            'role' => 'guru',
            'status' => 'aktif',
            'wajib_ganti_password' => false,
            'no_hp' => $guru->user->no_hp,
            'avatar_url' => null,
            'guru' => [
                'id' => $guru->id,
                'bisa_kelola_keuangan' => false,
                'kelas_diampu' => [['id' => $kelas->id, 'nama' => 'TK A1']],
            ],
            'wali_murid' => null,
            'permissions' => ['kelola_keuangan' => false],
        ]);

    $token = $response->json('data.token');
    expect($token)->toBeString()
        ->and($guru->user->tokens()->sole()->name)->toBe('web')
        ->and($guru->user->fresh()?->last_login_at)->not->toBeNull();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $guru->user_id);
});

it('memberi izin kelola keuangan ke Kepala Sekolah', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'kepsek2026']);

    $this->postJson('/api/v1/auth/login', ['email' => $kepsek->email, 'password' => 'kepsek2026'])
        ->assertOk()
        ->assertJsonPath('data.user.role', 'super_admin')
        ->assertJsonPath('data.user.permissions.kelola_keuangan', true);
});

it('menamai token sesuai perangkat', function () {
    $guru = buatGuru();
    $guru->user->update(['password' => 'rahasia123']);

    $this->postJson('/api/v1/auth/login', ['email' => $guru->user->email, 'password' => 'rahasia123', 'perangkat' => 'mobile'])->assertOk();

    expect($guru->user->tokens()->sole()->name)->toBe('mobile');
});

it('menolak email atau password yang salah tanpa membedakan penyebabnya', function (Closure $kredensial) {
    $guru = buatGuru();
    $guru->user->update(['password' => 'rahasia123']);

    $this->postJson('/api/v1/auth/login', $kredensial($guru->user))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.email', ['Email atau password salah.']);
})->with([
    'password salah' => [fn (User $user) => ['email' => $user->email, 'password' => 'bukanpassword1']],
    'email tidak terdaftar' => [fn (User $user) => ['email' => 'tidak.ada@tkta8.test', 'password' => 'rahasia123']],
    'akun wali murid' => [fn (User $user) => ['email' => User::factory()->waliMurid()->create(['password' => 'rahasia123'])->email, 'password' => 'rahasia123']],
]);

it('menolak login akun yang belum atau tidak lagi aktif dengan kode yang sesuai', function (StatusAkun $status, string $kode, string $pesan) {
    $guru = buatGuru($status, ['alasan_penolakan' => $status === StatusAkun::Ditolak ? 'Ijazah belum dilampirkan.' : null]);
    $guru->user->update(['password' => 'rahasia123']);

    $this->postJson('/api/v1/auth/login', ['email' => $guru->user->email, 'password' => 'rahasia123'])
        ->assertForbidden()
        ->assertJsonPath('code', $kode)
        ->assertJsonPath('message', $pesan)
        ->assertJsonPath('errors', null);

    expect($guru->user->tokens()->count())->toBe(0);
})->with([
    'pending' => [StatusAkun::Pending, 'ACCOUNT_PENDING', 'Akun Anda masih menunggu persetujuan Kepala Sekolah.'],
    'ditolak' => [StatusAkun::Ditolak, 'ACCOUNT_REJECTED', 'Pendaftaran akun Anda ditolak. Alasan: Ijazah belum dilampirkan.'],
    'nonaktif' => [StatusAkun::Nonaktif, 'ACCOUNT_INACTIVE', 'Akun Anda sudah dinonaktifkan. Hubungi pihak sekolah untuk mengaktifkannya kembali.'],
]);

it('tidak membocorkan status akun kalau passwordnya salah', function () {
    $guru = buatGuru(StatusAkun::Pending);
    $guru->user->update(['password' => 'rahasia123']);

    $this->postJson('/api/v1/auth/login', ['email' => $guru->user->email, 'password' => 'salahsalah1'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('membatasi login 5 kali per menit untuk email dan IP yang sama', function () {
    $guru = buatGuru();

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => $guru->user->email, 'password' => 'salahsalah1'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', ['email' => $guru->user->email, 'password' => 'salahsalah1'])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

    $this->postJson('/api/v1/auth/login', ['email' => 'lain@tkta8.test', 'password' => 'salahsalah1'])->assertStatus(422);
});

it('menyimpan password sebagai hash', function () {
    $guru = buatGuru();
    $guru->user->update(['password' => 'rahasia123']);

    expect($guru->user->fresh()?->password)->not->toBe('rahasia123')
        ->and(Hash::check('rahasia123', (string) $guru->user->fresh()?->password))->toBeTrue();
});
