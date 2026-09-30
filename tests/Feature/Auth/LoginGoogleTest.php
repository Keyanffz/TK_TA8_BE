<?php

use App\Enums\StatusAkun;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

function guruBeremail(string $email, StatusAkun $status = StatusAkun::Aktif, ?string $googleSub = null): User
{
    return Guru::factory()->for(User::factory()->status($status)->state(['email' => $email, 'google_sub' => $googleSub]))->create()->user;
}

beforeEach(function () {
    palsukanGoogle($this);
});

it('memberi token dan data user sesuai kontrak A7 saat guru login dengan Google', function () {
    $guru = guruBeremail('nur.aini@gmail.com');
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create(['nama' => 'TK A1', 'wali_kelas_id' => $guru->guru?->id]);

    $response = $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])
        ->assertOk()
        ->assertJsonPath('message', 'Berhasil masuk.')
        ->assertJsonPath('data.user', [
            'id' => $guru->id,
            'name' => $guru->name,
            'email' => 'nur.aini@gmail.com',
            'username' => null,
            'role' => 'guru',
            'status' => 'aktif',
            'wajib_ganti_password' => false,
            'no_hp' => $guru->no_hp,
            'avatar_url' => null,
            'guru' => [
                'id' => $guru->guru?->id,
                'bisa_kelola_keuangan' => false,
                'kelas_diampu' => [['id' => $kelas->id, 'nama' => 'TK A1']],
            ],
            'wali_murid' => null,
            'permissions' => ['kelola_keuangan' => false],
        ]);

    expect($guru->fresh()?->google_sub)->toBe(SUB_GOOGLE)
        ->and($guru->fresh()?->last_login_at)->not->toBeNull()
        ->and($guru->tokens()->sole()->name)->toBe('web');

    $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $guru->id);
});

it('membolehkan Kepala Sekolah login dengan Google', function () {
    $kepsek = buatKepalaSekolah();

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle(['email' => $kepsek->email]), 'perangkat' => 'mobile'])
        ->assertOk()
        ->assertJsonPath('data.user.role', 'super_admin')
        ->assertJsonPath('data.user.permissions.kelola_keuangan', true);

    expect($kepsek->tokens()->sole()->name)->toBe('mobile');
});

it('mencocokkan email tanpa membedakan huruf besar', function () {
    $guru = guruBeremail('nur.aini@gmail.com');

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle(['email' => 'Nur.Aini@Gmail.com'])])
        ->assertOk()
        ->assertJsonPath('data.user.id', $guru->id);
});

it('menerima login berikutnya dari akun Google yang sama', function () {
    guruBeremail('nur.aini@gmail.com', googleSub: SUB_GOOGLE);

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])->assertOk();
});

it('menolak akun Google lain walaupun emailnya sama', function () {
    $guru = guruBeremail('nur.aini@gmail.com', googleSub: '100000000000000000001');

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])
        ->assertStatus(422)
        ->assertJsonPath('errors.credential', ['Email ini sudah terhubung dengan akun Google lain. Hubungi Kepala Sekolah untuk memperbarui email akun Anda.']);

    expect($guru->tokens()->count())->toBe(0)
        ->and($guru->fresh()?->google_sub)->toBe('100000000000000000001');
});

it('menolak akun Google yang sudah terikat ke akun staff lain', function () {
    guruBeremail('guru.lama@gmail.com', googleSub: SUB_GOOGLE);
    $guru = guruBeremail('nur.aini@gmail.com');

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['credential']);

    expect($guru->fresh()?->google_sub)->toBeNull();
});

it('menolak ID token yang tidak sah', function (Closure $credential) {
    guruBeremail('nur.aini@gmail.com');

    $this->postJson('/api/v1/auth/staff/google', ['credential' => $credential()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.credential', ['Login Google tidak valid atau sudah kedaluwarsa. Coba masuk dengan Google lagi.']);

    expect(User::query()->whereNotNull('google_sub')->exists())->toBeFalse();
})->with([
    'aud untuk aplikasi lain' => [fn () => idTokenGoogle(['aud' => '999-aplikasi-lain.apps.googleusercontent.com'])],
    'kedaluwarsa' => [fn () => idTokenGoogle(['iat' => now()->subHours(2)->timestamp, 'exp' => now()->subHour()->timestamp])],
    'tanpa exp' => [fn () => idTokenGoogle(['exp' => null])],
    'penerbit bukan Google' => [fn () => idTokenGoogle(['iss' => 'https://accounts.example.org'])],
    'ditandatangani kunci lain' => [fn () => idTokenGoogle(kunciPenanda: kunciGooglePalsu('kunci-penyerang')['privat'])],
    'isi token diubah' => [function () {
        [$kepala, , $tandaTangan] = explode('.', idTokenGoogle());

        return $kepala.'.'.JWT::urlsafeB64Encode(json_encode(['email' => 'nur.aini@gmail.com'])).'.'.$tandaTangan;
    }],
    'bukan JWT' => [fn () => 'bukan-token-google'],
]);

it('menolak email Google yang belum terverifikasi', function () {
    guruBeremail('nur.aini@gmail.com');

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle(['email_verified' => false])])
        ->assertStatus(422)
        ->assertJsonPath('errors.credential', ['Email akun Google ini belum terverifikasi. Pakai akun Google yang emailnya sudah terverifikasi.']);
});

it('menolak email yang tidak terdaftar tanpa membuat akun baru', function (Closure $email) {
    $email = $email();
    $jumlahAkun = User::query()->count();

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle(['email' => $email])])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.credential', ['Akun Google ini tidak terdaftar sebagai guru atau Kepala Sekolah. Minta Kepala Sekolah mendaftarkan email Google Anda.']);

    expect(User::query()->count())->toBe($jumlahAkun)
        ->and(User::query()->whereNotNull('google_sub')->exists())->toBeFalse();
})->with([
    'email tidak terdaftar' => [fn () => 'orang.lain@gmail.com'],
    'akun wali murid yang punya email' => [fn () => User::factory()->waliMurid()->create(['email' => 'dewi.lestari@wali.tkta8.test'])->email],
]);

it('menolak akun nonaktif dengan ACCOUNT_INACTIVE tanpa mengikat akun Google', function () {
    $guru = guruBeremail('nur.aini@gmail.com', StatusAkun::Nonaktif);

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE')
        ->assertJsonPath('message', 'Akun Anda sudah dinonaktifkan. Hubungi pihak sekolah untuk mengaktifkannya kembali.');

    expect($guru->tokens()->count())->toBe(0)
        ->and($guru->fresh()?->google_sub)->toBeNull();
});

it('menyimpan kunci publik Google di cache sesuai max-age', function () {
    guruBeremail('nur.aini@gmail.com');

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])->assertOk();
    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])->assertOk();

    Http::assertSentCount(1);
});

it('membalas 503 kalau GOOGLE_CLIENT_ID belum diisi', function () {
    config(['services.google.client_id' => null]);

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])
        ->assertStatus(503)
        ->assertJsonPath('code', 'SERVER_ERROR')
        ->assertJsonPath('message', 'Login Google belum dikonfigurasi. Hubungi Kepala Sekolah.');
});

it('membalas 503 kalau kunci publik Google tidak bisa diambil', function () {
    $this->googleGangguan = true;

    $this->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle()])
        ->assertStatus(503)
        ->assertJsonPath('code', 'SERVER_ERROR')
        ->assertJsonPath('message', 'Login Google sedang tidak bisa diproses. Coba lagi beberapa saat lagi.');
});

it('mewajibkan credential', function () {
    $this->postJson('/api/v1/auth/staff/google', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['credential']);
});

it('membatasi login Google 10 kali per menit per IP dengan Retry-After', function () {
    $this->freezeTime();

    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/auth/staff/google', ['credential' => 'bukan-token-google'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/staff/google', ['credential' => 'bukan-token-google'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
        ->postJson('/api/v1/auth/staff/google', ['credential' => 'bukan-token-google'])
        ->assertStatus(422);
});
