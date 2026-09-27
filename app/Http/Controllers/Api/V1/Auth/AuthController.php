<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LoginWaliRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
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
     * Login wali murid dengan NIS anak sebagai `username` dan password.
     *
     * Password awal akun wali adalah tanggal lahir anak (DDMMYYYY). Selama `user.wajib_ganti_password` bernilai
     * `true`, token hanya bisa dipakai untuk `GET /auth/me`, `PUT /auth/password`, dan `POST /auth/logout`;
     * endpoint lain membalas 403 `PASSWORD_WAJIB_DIGANTI`. Dibatasi 5 percobaan per menit per NIS dan IP.
     */
    public function loginWali(LoginWaliRequest $request): JsonResponse
    {
        $hasil = $this->auth->loginWali(
            $request->string('username')->toString(),
            $request->string('password')->toString(),
            $request->perangkat(),
        );

        return ApiResponse::success([
            'token' => $hasil['token'],
            'user' => new UserResource($hasil['user']),
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
