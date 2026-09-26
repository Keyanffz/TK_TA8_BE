<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginGoogleRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\GoogleLoginService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Login Kepala Sekolah dan guru dengan email dan password.
     *
     * Akun yang belum atau tidak lagi aktif ditolak 403 dengan kode `ACCOUNT_PENDING`,
     * `ACCOUNT_REJECTED` (alasan penolakan ada di `message`), atau `ACCOUNT_INACTIVE`.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $hasil = $this->auth->loginEmail(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->perangkat(),
        );

        return ApiResponse::success([
            'token' => $hasil['token'],
            'user' => new UserResource($hasil['user']),
        ], 'Berhasil masuk.');
    }

    /**
     * Login wali murid dengan ID token dari Google Identity Services.
     *
     * Wali yang baru pertama kali login otomatis dibuatkan akun (`is_new: true`).
     */
    public function google(LoginGoogleRequest $request, GoogleLoginService $google): JsonResponse
    {
        $hasil = $google->login($request->string('id_token')->toString(), $request->perangkat());

        return ApiResponse::success([
            'token' => $hasil['token'],
            'user' => new UserResource($hasil['user']),
            'is_new' => $hasil['is_new'],
        ], 'Berhasil masuk.');
    }

    /**
     * Data user yang sedang login, termasuk profil guru/wali dan daftar anak untuk wali murid.
     */
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::success(new UserResource($user));
    }

    /**
     * Mencabut token yang sedang dipakai.
     */
    public function logout(#[CurrentUser] User $user): JsonResponse
    {
        $this->auth->logout($user);

        return ApiResponse::success(null, 'Anda sudah keluar.');
    }
}
