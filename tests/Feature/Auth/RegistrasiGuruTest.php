<?php

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Models\User;
use App\Notifications\GuruBaruNotification;
use Illuminate\Support\Facades\Notification;

function dataPendaftaranGuru(array $timpa = []): array
{
    return [
        'name' => 'Siti Nurhaliza',
        'email' => 'siti.nurhaliza@gmail.com',
        'password' => 'mengajar2026',
        'password_confirmation' => 'mengajar2026',
        'no_hp' => '081234567890',
        'jenis_kelamin' => 'P',
        ...$timpa,
    ];
}

it('mendaftarkan guru dengan status pending dan memberi tahu Kepala Sekolah', function () {
    Notification::fake();
    $kepsek = buatKepalaSekolah();

    $this->postJson('/api/v1/auth/register-guru', dataPendaftaranGuru())
        ->assertCreated()
        ->assertJsonPath('data', null)
        ->assertJsonPath('message', 'Pendaftaran terkirim. Akun Anda menunggu persetujuan Kepala Sekolah; kami kirim email setelah disetujui.');

    $user = User::query()->where('email', 'siti.nurhaliza@gmail.com')->sole();
    expect($user->role)->toBe(Role::Guru)
        ->and($user->status)->toBe(StatusAkun::Pending)
        ->and($user->guru?->jenis_kelamin->value)->toBe('P')
        ->and($user->tokens()->count())->toBe(0);

    Notification::assertSentTo($kepsek, GuruBaruNotification::class, function (GuruBaruNotification $notifikasi) use ($kepsek, $user): bool {
        return $notifikasi->toDatabase($kepsek) === [
            'jenis' => 'guru_baru',
            'judul' => 'Pendaftaran guru baru',
            'pesan' => 'Siti Nurhaliza mendaftar sebagai guru dan menunggu persetujuan Anda.',
            'url' => "/dashboard/guru/{$user->guru?->id}",
        ];
    });
});

it('langsung bisa dicoba login dan mendapat ACCOUNT_PENDING', function () {
    $this->postJson('/api/v1/auth/register-guru', dataPendaftaranGuru())->assertCreated();

    $this->postJson('/api/v1/auth/login', ['email' => 'siti.nurhaliza@gmail.com', 'password' => 'mengajar2026'])
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_PENDING');
});

it('memvalidasi data pendaftaran guru', function (array $timpa, string $field) {
    User::factory()->create(['email' => 'terpakai@gmail.com']);

    $this->postJson('/api/v1/auth/register-guru', dataPendaftaranGuru($timpa))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'email sudah terdaftar' => [['email' => 'terpakai@gmail.com'], 'email'],
    'password tanpa angka' => [['password' => 'hanyahuruf', 'password_confirmation' => 'hanyahuruf'], 'password'],
    'konfirmasi password beda' => [['password_confirmation' => 'lainlagi2026'], 'password'],
    'nomor HP bukan 08' => [['no_hp' => '+6281234567890'], 'no_hp'],
    'jenis kelamin tidak dikenal' => [['jenis_kelamin' => 'X'], 'jenis_kelamin'],
]);

it('menulis pesan validasi dalam bahasa Indonesia', function () {
    $this->postJson('/api/v1/auth/register-guru', dataPendaftaranGuru(['no_hp' => '62812']))
        ->assertJsonPath('errors.no_hp', ['Nomor HP harus diawali 08 dan hanya berisi angka, misalnya 081234567890.']);
});
