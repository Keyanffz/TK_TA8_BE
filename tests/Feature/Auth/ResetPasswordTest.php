<?php

use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

const PESAN_TAUTAN_TERKIRIM = 'Jika email ini terdaftar sebagai akun guru atau Kepala Sekolah, tautan untuk mengatur ulang password sudah dikirim. Periksa kotak masuk atau folder spam.';

it('mengirim tautan reset yang mengarah ke halaman frontend', function () {
    Notification::fake();
    $guru = buatGuru();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $guru->user->email])
        ->assertOk()
        ->assertJsonPath('message', PESAN_TAUTAN_TERKIRIM);

    Notification::assertSentTo($guru->user, ResetPasswordNotification::class, function (ResetPasswordNotification $notifikasi) use ($guru): bool {
        $email = $notifikasi->toMail($guru->user);

        return $email->subject === 'Atur ulang password'
            && str_starts_with((string) $email->actionUrl, 'http://localhost:3000/reset-password?token=')
            && str_contains((string) $email->actionUrl, 'email='.urlencode($guru->user->email));
    });
});

it('memberi respons yang sama untuk email yang tidak bisa direset', function (Closure $email) {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $email()])
        ->assertOk()
        ->assertJsonPath('message', PESAN_TAUTAN_TERKIRIM);

    Notification::assertNothingSent();
})->with([
    'email tidak terdaftar' => [fn () => 'tidak.ada@gmail.com'],
    'akun wali murid' => [fn () => WaliMurid::factory()->create()->user->email],
]);

it('mengatur password baru dan mencabut semua sesi lama', function () {
    $guru = buatGuru();
    $guru->user->createToken('web');
    $guru->user->createToken('mobile');
    $token = Password::broker()->createToken($guru->user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $guru->user->email,
        'password' => 'passwordBaru2026',
        'password_confirmation' => 'passwordBaru2026',
    ])->assertOk()->assertJsonPath('message', 'Password berhasil diatur ulang. Silakan masuk dengan password baru.');

    expect(Hash::check('passwordBaru2026', (string) $guru->user->fresh()?->password))->toBeTrue()
        ->and($guru->user->tokens()->count())->toBe(0);

    $this->postJson('/api/v1/auth/login', ['email' => $guru->user->email, 'password' => 'passwordBaru2026'])->assertOk();
});

it('menolak token reset yang salah atau sudah dipakai', function () {
    $guru = buatGuru();
    $token = Password::broker()->createToken($guru->user);
    $data = ['email' => $guru->user->email, 'password' => 'passwordBaru2026', 'password_confirmation' => 'passwordBaru2026'];

    $this->postJson('/api/v1/auth/reset-password', [...$data, 'token' => 'token-karangan'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email', ['Tautan reset password tidak valid atau sudah kedaluwarsa. Minta tautan baru dari halaman lupa password.']);

    $this->postJson('/api/v1/auth/reset-password', [...$data, 'token' => $token])->assertOk();
    $this->postJson('/api/v1/auth/reset-password', [...$data, 'token' => $token])->assertStatus(422);
});

it('menolak token reset yang kedaluwarsa', function () {
    $guru = buatGuru();
    $token = Password::broker()->createToken($guru->user);
    $this->travel(61)->minutes();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $guru->user->email,
        'password' => 'passwordBaru2026',
        'password_confirmation' => 'passwordBaru2026',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('tidak mereset password akun wali murid', function () {
    $wali = User::factory()->waliMurid()->create();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'apa-saja',
        'email' => $wali->email,
        'password' => 'passwordBaru2026',
        'password_confirmation' => 'passwordBaru2026',
    ])->assertStatus(422);

    expect(Hash::check('passwordBaru2026', (string) $wali->fresh()?->password))->toBeFalse();
});
