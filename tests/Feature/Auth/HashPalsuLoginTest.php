<?php

use App\Models\User;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Hash;

/**
 * Waktu respons tidak diukur supaya test tidak flaky. Yang dipastikan: jalur akun tidak ditemukan tetap memanggil
 * Hash::check sekali terhadap hash bcrypt dengan cost yang sama dengan hash password akun.
 *
 * @return ArrayObject<int, array{nilai: string, hash: string}>
 */
function pantauHashCheck(): ArrayObject
{
    $dicek = new ArrayObject;

    $hasher = Mockery::mock(HashManager::class, [app()])->makePartial();
    $hasher->shouldReceive('check')
        ->withArgs(function (string $nilai, string $hash) use ($dicek): bool {
            $dicek[] = ['nilai' => $nilai, 'hash' => $hash];

            return true;
        })
        ->passthru();

    Hash::swap($hasher);

    return $dicek;
}

it('tetap menjalankan Hash::check dengan cost yang sama saat akun login tidak ditemukan', function (Closure $siapkan, string $url, array $kredensial, string $field, string $pesan) {
    $siapkan();
    $dicek = pantauHashCheck();

    $this->postJson($url, $kredensial)
        ->assertStatus(422)
        ->assertJsonPath("errors.{$field}", [$pesan]);

    expect($dicek)->toHaveCount(1)
        ->and($dicek[0]['nilai'])->toBe($kredensial['password'])
        ->and(Hash::info($dicek[0]['hash']))->toMatchArray([
            'algoName' => 'bcrypt',
            'options' => ['cost' => (int) config('hashing.bcrypt.rounds')],
        ]);
})->with([
    'staff, email tidak terdaftar' => [
        fn () => null,
        '/api/v1/auth/staff/login', ['email' => 'tidak.ada@tkta8.test', 'password' => 'rahasia123'], 'email', 'Email atau password salah.',
    ],
    'staff, akun wali murid' => [
        fn () => User::factory()->waliMurid()->create(['email' => 'dewi.lestari@wali.tkta8.test', 'password' => 'rahasia123']),
        '/api/v1/auth/staff/login', ['email' => 'dewi.lestari@wali.tkta8.test', 'password' => 'rahasia123'], 'email', 'Email atau password salah.',
    ],
    'wali, NIS tidak terdaftar' => [
        fn () => null,
        '/api/v1/auth/wali/login', ['username' => 'TA20269999', 'password' => '15032021'], 'username', 'NIS atau password salah.',
    ],
    'wali, akun guru' => [
        fn () => buatGuru()->user->update(['username' => 'TA20260099', 'password' => 'rahasia123']),
        '/api/v1/auth/wali/login', ['username' => 'TA20260099', 'password' => 'rahasia123'], 'username', 'NIS atau password salah.',
    ],
]);

it('memakai hash palsu yang sama di setiap percobaan', function () {
    $dicek = pantauHashCheck();

    $this->postJson('/api/v1/auth/staff/login', ['email' => 'tidak.ada@tkta8.test', 'password' => 'rahasia123'])->assertStatus(422);
    $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20269999', 'password' => '15032021'])->assertStatus(422);

    expect($dicek)->toHaveCount(2)
        ->and($dicek[1]['hash'])->toBe($dicek[0]['hash']);
});
