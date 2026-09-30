<?php

use App\Enums\StatusAkun;
use App\Models\WaliMurid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

it('meminta login untuk endpoint yang butuh sesi', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('menolak token milik akun yang sudah dinonaktifkan', function () {
    $guru = buatGuru();
    $token = $guru->user->createToken('web')->plainTextToken;
    $guru->user->update(['status' => StatusAkun::Nonaktif]);

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
});

it('logout hanya mencabut token yang sedang dipakai', function () {
    $guru = buatGuru();
    $tokenWeb = $guru->user->createToken('web')->plainTextToken;
    $guru->user->createToken('mobile');

    $this->withToken($tokenWeb)->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Anda sudah keluar.');

    expect($guru->user->tokens()->pluck('name')->all())->toBe(['mobile'])
        ->and(PersonalAccessToken::findToken($tokenWeb))->toBeNull();
});

it('memperbarui nama, nomor HP, dan foto profil lewat PUT multipart', function () {
    Storage::fake('public');
    $guru = buatGuru();
    $guru->user->update(['avatar_path' => 'avatar/lama.jpg']);
    Storage::disk('public')->put('avatar/lama.jpg', 'isi lama');

    $response = $this->actingAs($guru->user)->put('/api/v1/auth/profil', [
        'name' => 'Rina Kartika',
        'no_hp' => '085712345678',
        'avatar' => UploadedFile::fake()->image('foto.png', 2400, 1800),
    ])->assertOk()->assertJsonPath('message', 'Profil tersimpan.');

    $user = $guru->user->fresh();
    expect($user?->name)->toBe('Rina Kartika')
        ->and($user?->no_hp)->toBe('085712345678')
        ->and($user?->avatar_path)->toStartWith('avatar/')->toEndWith('.jpg')
        ->and($response->json('data.avatar_url'))->toBe(Storage::disk('public')->url((string) $user?->avatar_path));

    Storage::disk('public')->assertExists((string) $user?->avatar_path);
    Storage::disk('public')->assertMissing('avatar/lama.jpg');
    expect(getimagesizefromstring((string) Storage::disk('public')->get((string) $user?->avatar_path)))
        ->toMatchArray([0 => 1600, 1 => 1200, 'mime' => 'image/jpeg']);
});

it('menolak foto profil yang bukan gambar atau lebih dari 5 MB', function (UploadedFile $file) {
    $guru = buatGuru();

    $this->actingAs($guru->user)->putJson('/api/v1/auth/profil', ['name' => 'Rina', 'avatar' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['avatar']);
})->with([
    'PDF' => [fn () => UploadedFile::fake()->create('ijazah.pdf', 100, 'application/pdf')],
    '6 MB' => [fn () => UploadedFile::fake()->image('besar.jpg')->size(6 * 1024)],
]);

it('membolehkan wali murid memperbarui profil akunnya', function () {
    $wali = WaliMurid::factory()->create();

    $this->actingAs($wali->user)->putJson('/api/v1/auth/profil', ['name' => 'Dewi Lestari', 'no_hp' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'Dewi Lestari')
        ->assertJsonPath('data.no_hp', $wali->user->no_hp);
});

it('mengganti password dan mengeluarkan sesi di perangkat lain', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'rahasia123']);
    $tokenIni = $kepsek->createToken('web')->plainTextToken;
    $kepsek->createToken('mobile');

    $this->withToken($tokenIni)->putJson('/api/v1/auth/password', [
        'current_password' => 'rahasia123',
        'password' => 'rahasiaBaru456',
        'password_confirmation' => 'rahasiaBaru456',
    ])->assertOk();

    expect(Hash::check('rahasiaBaru456', (string) $kepsek->fresh()?->password))->toBeTrue()
        ->and($kepsek->tokens()->pluck('name')->all())->toBe(['web']);

    $this->withToken($tokenIni)->getJson('/api/v1/auth/me')->assertOk();
});

it('menolak ganti password dengan password lama yang salah atau sama', function (array $data, string $field) {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'rahasia123']);

    $this->withToken($kepsek->createToken('web')->plainTextToken)
        ->putJson('/api/v1/auth/password', $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'password lama salah' => [['current_password' => 'bukanItu99', 'password' => 'rahasiaBaru456', 'password_confirmation' => 'rahasiaBaru456'], 'current_password'],
    'password baru sama' => [['current_password' => 'rahasia123', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'], 'password'],
]);

it('memakai istilah password di pesan password lama yang salah', function () {
    $kepsek = buatKepalaSekolah();
    $kepsek->update(['password' => 'rahasia123']);

    $this->withToken($kepsek->createToken('web')->plainTextToken)
        ->putJson('/api/v1/auth/password', ['current_password' => 'bukanItu99', 'password' => 'rahasiaBaru456', 'password_confirmation' => 'rahasiaBaru456'])
        ->assertJsonPath('errors.current_password', ['Password salah.']);
});

it('menolak ganti password untuk guru karena guru login lewat Google', function () {
    $guru = buatGuru()->user;

    $this->withToken($guru->createToken('web')->plainTextToken)
        ->putJson('/api/v1/auth/password', ['current_password' => 'apaSaja123', 'password' => 'rahasiaBaru456', 'password_confirmation' => 'rahasiaBaru456'])
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($guru->fresh()?->password)->toBeNull();
});
