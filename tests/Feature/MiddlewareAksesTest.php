<?php

use App\Enums\StatusAkun;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'akun.aktif'])->prefix('api/v1/_uji')->group(function () {
        Route::get('/akun', fn () => 'ok');
        Route::get('/khusus-kepsek', fn () => 'ok')->middleware('role:super_admin');
        Route::get('/kepsek-dan-guru', fn () => 'ok')->middleware('role:super_admin,guru');
    });
});

it('menolak token akun nonaktif dengan ACCOUNT_INACTIVE', function () {
    Sanctum::actingAs(User::factory()->status(StatusAkun::Nonaktif)->create());

    $this->getJson('/api/v1/_uji/akun')
        ->assertForbidden()
        ->assertJson([
            'success' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Akun Anda sudah dinonaktifkan. Hubungi pihak sekolah untuk mengaktifkannya kembali.',
            'errors' => null,
        ]);
});

it('meloloskan token akun aktif', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/_uji/akun')->assertOk();
});

it('menolak role yang tidak diizinkan route dengan 403 FORBIDDEN', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/_uji/khusus-kepsek')
        ->assertForbidden()
        ->assertJson(['code' => 'FORBIDDEN']);
});

it('meloloskan role yang tercantum di route', function () {
    Sanctum::actingAs(User::factory()->superAdmin()->create());
    $this->getJson('/api/v1/_uji/khusus-kepsek')->assertOk();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/_uji/kepsek-dan-guru')->assertOk();
});

it('menolak wali murid di route khusus kepala sekolah dan guru', function () {
    Sanctum::actingAs(User::factory()->waliMurid()->create());

    $this->getJson('/api/v1/_uji/kepsek-dan-guru')->assertForbidden();
});
