<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginGoogleRequest;
use App\Http\Requests\Auth\LoginStaffRequest;
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
     * Login Kepala Sekolah dengan email dan password.
     *
     * Hanya untuk Kepala Sekolah (`user.role` = `super_admin`). Guru tidak punya password dan masuk lewat
     * `POST /auth/staff/google`. Email yang tidak terdaftar, password salah, akun guru, dan akun wali murid mendapat
     * balasan yang sama. Akun nonaktif ditolak 403 `ACCOUNT_INACTIVE`. Dibatasi 3 percobaan per menit per email dan
     * IP, dan 10 percobaan per menit per IP; balasan 429 membawa header `Retry-After`.
     */
    public function loginStaff(LoginStaffRequest $request): JsonResponse
    {
        $hasil = $this->auth->loginStaff(
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
     * Login guru dan Kepala Sekolah dengan akun Google.
     *
     * `credential` adalah ID token dari Google Identity Services. Server memverifikasi tanda tangan, `aud`
     * (GOOGLE_CLIENT_ID), `iss`, `exp`, dan `email_verified`, lalu mencocokkan email di token dengan akun guru atau
     * Kepala Sekolah. Akun Google yang dipakai pertama kali diikat ke akun itu; login berikutnya dari akun Google lain
     * dengan email yang sama ditolak. Endpoint ini tidak pernah membuat akun: email yang tidak terdaftar ditolak 422
     * di field `credential`. Akun nonaktif ditolak 403 `ACCOUNT_INACTIVE`. Kalau GOOGLE_CLIENT_ID belum diisi atau
     * kunci publik Google tidak bisa diunduh, balasannya 503 `SERVER_ERROR`. Dibatasi 10 percobaan per menit per IP;
     * balasan 429 membawa header `Retry-After`.
     */
    public function loginGoogle(LoginGoogleRequest $request): JsonResponse
    {
        $hasil = $this->auth->loginGoogle($request->string('credential')->toString(), $request->perangkat());

        return ApiResponse::success([
            'token' => $hasil['token'],
            'user' => new UserResource($hasil['user']),
        ], 'Berhasil masuk.');
    }

    /**
     * Login wali murid dengan NIS anak sebagai `username` dan password.
     *
     * Role dikirim di `user.role` (selalu `wali_murid`). NIS yang tidak terdaftar, password salah, dan akun selain
     * wali murid mendapat balasan yang sama. Password awal akun wali adalah tanggal lahir anak (DDMMYYYY). Selama
     * `user.wajib_ganti_password` bernilai `true`, token hanya bisa dipakai untuk `GET /auth/me`,
     * `PUT /auth/password`, dan `POST /auth/logout`; endpoint lain membalas 403 `PASSWORD_WAJIB_DIGANTI`.
     * Dibatasi 5 percobaan per menit per NIS dan IP, dan 20 percobaan per menit per IP; balasan 429 membawa
     * header `Retry-After`.
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
