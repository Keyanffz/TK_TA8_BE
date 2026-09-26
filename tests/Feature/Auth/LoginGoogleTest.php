<?php

use App\Enums\Hubungan;
use App\Enums\StatusAkun;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use App\Services\GoogleIdTokenVerifier;
use Mockery\MockInterface;

/**
 * @param  array{sub?: string, email?: string, name?: string, email_verified?: bool}|null  $profil
 */
function tokenGoogleMenghasilkan(?array $profil): void
{
    test()->mock(GoogleIdTokenVerifier::class, function (MockInterface $mock) use ($profil): void {
        $mock->shouldReceive('verifikasi')->with('id-token-google')->andReturn($profil === null ? null : [
            'sub' => '109876543210987654321',
            'email' => 'dewi.lestari@gmail.com',
            'name' => 'Dewi Lestari',
            'email_verified' => true,
            ...$profil,
        ]);
    });
}

it('membuat akun wali murid baru saat pertama kali login Google', function () {
    tokenGoogleMenghasilkan([]);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google', 'perangkat' => 'mobile'])
        ->assertOk()
        ->assertJsonPath('data.is_new', true)
        ->assertJsonPath('data.user.role', 'wali_murid')
        ->assertJsonPath('data.user.name', 'Dewi Lestari')
        ->assertJsonPath('data.user.guru', null)
        ->assertJsonPath('data.user.wali_murid.profil_lengkap', false)
        ->assertJsonPath('data.user.wali_murid.anak', [])
        ->assertJsonPath('data.user.permissions.kelola_keuangan', false);

    $user = User::query()->where('email', 'dewi.lestari@gmail.com')->sole();
    expect($user->google_id)->toBe('109876543210987654321')
        ->and($user->password)->toBeNull()
        ->and($user->status)->toBe(StatusAkun::Aktif)
        ->and($user->waliMurid)->not->toBeNull()
        ->and($user->tokens()->sole()->name)->toBe('mobile');
});

it('masuk ke akun wali yang sudah ada beserta daftar anaknya', function () {
    $wali = WaliMurid::factory()->for(User::factory()->waliMurid()->state(['google_id' => '109876543210987654321']))->create();
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create(['nama' => 'TK B2']);
    $anak = Murid::factory()->create(['nama_panggilan' => 'Raka']);
    $anak->kelas()->attach($kelas);
    $wali->murid()->attach($anak, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    tokenGoogleMenghasilkan(['email' => $wali->user->email]);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google'])
        ->assertOk()
        ->assertJsonPath('data.is_new', false)
        ->assertJsonPath('data.user.id', $wali->user_id)
        ->assertJsonPath('data.user.wali_murid.profil_lengkap', true)
        ->assertJsonPath('data.user.wali_murid.anak', [
            ['id' => $anak->id, 'nama_panggilan' => 'Raka', 'kelas' => 'TK B2', 'foto_url' => null],
        ]);

    expect(User::query()->count())->toBe(1);
});

it('mengaitkan akun Google ke wali yang emailnya sudah terdaftar', function () {
    $wali = WaliMurid::factory()->for(User::factory()->waliMurid()->state(['google_id' => null, 'email' => 'dewi.lestari@gmail.com']))->create();
    tokenGoogleMenghasilkan([]);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google'])
        ->assertOk()
        ->assertJsonPath('data.is_new', false);

    expect($wali->user->fresh()?->google_id)->toBe('109876543210987654321');
});

it('menolak ID token yang tidak valid', function () {
    tokenGoogleMenghasilkan(null);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google'])
        ->assertStatus(422)
        ->assertJsonPath('errors.id_token', ['Login Google tidak valid atau sudah kedaluwarsa. Silakan coba masuk lagi.']);

    expect(User::query()->count())->toBe(0);
});

it('menolak email Google yang belum terverifikasi', function () {
    tokenGoogleMenghasilkan(['email_verified' => false]);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google'])
        ->assertStatus(422)
        ->assertJsonPath('errors.id_token', ['Email akun Google ini belum terverifikasi. Verifikasi email di Google lalu coba lagi.']);
});

it('mengarahkan pemilik email guru ke login email dan password', function () {
    $guru = buatGuru();
    tokenGoogleMenghasilkan(['email' => $guru->user->email]);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Email ini terdaftar sebagai akun guru atau Kepala Sekolah. Gunakan login email & password.');

    expect($guru->user->fresh()?->google_id)->toBeNull();
});

it('menolak wali murid yang akunnya dinonaktifkan', function () {
    WaliMurid::factory()->for(User::factory()->waliMurid()->status(StatusAkun::Nonaktif)->state(['google_id' => '109876543210987654321']))->create();
    tokenGoogleMenghasilkan([]);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google'])
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
});

it('membatasi login Google 10 kali per menit per IP', function () {
    tokenGoogleMenghasilkan(null);

    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-google'])->assertTooManyRequests();
});
