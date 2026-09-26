<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfilController;
use App\Http\Controllers\Api\V1\Auth\RegistrasiGuruController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Guru\GuruController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MediaController;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9]+');

Route::get('/health', HealthController::class)->name('health');
Route::get('/media/{token}', MediaController::class)->middleware('signed:relative')->name('media');

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/google', [AuthController::class, 'google'])->middleware('throttle:login-google');
    Route::post('/register-guru', RegistrasiGuruController::class);
    Route::post('/forgot-password', [ResetPasswordController::class, 'kirimTautan']);
    Route::post('/reset-password', [ResetPasswordController::class, 'reset']);
});

Route::middleware(['auth:sanctum', 'akun.aktif'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/profil', [ProfilController::class, 'perbarui']);
        Route::put('/password', [ProfilController::class, 'gantiPassword'])->middleware('role:super_admin,guru');
    });

    Route::middleware('role:super_admin')->group(function () {
        Route::get('/guru', [GuruController::class, 'index']);
        Route::post('/guru', [GuruController::class, 'store']);
        Route::get('/guru/{id}', [GuruController::class, 'show']);
        Route::put('/guru/{id}', [GuruController::class, 'update']);
        Route::post('/guru/{id}/setujui', [GuruController::class, 'setujui']);
        Route::post('/guru/{id}/tolak', [GuruController::class, 'tolak']);
        Route::patch('/guru/{id}/status', [GuruController::class, 'ubahStatus']);
    });
});
