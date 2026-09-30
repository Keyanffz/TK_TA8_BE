<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LupaPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ResetPasswordController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Mengirim tautan reset password ke email Kepala Sekolah. Guru tidak punya password (login lewat Google).
     *
     * Respons selalu sama, terdaftar atau tidak, supaya daftar email akun tidak bisa ditebak.
     */
    public function kirimTautan(LupaPasswordRequest $request): JsonResponse
    {
        $this->auth->kirimTautanResetPassword($request->string('email')->toString());

        return ApiResponse::success(
            null,
            'Jika email ini terdaftar sebagai akun Kepala Sekolah, tautan untuk mengatur ulang password sudah dikirim. Periksa kotak masuk atau folder spam.',
        );
    }

    /**
     * Mengatur password baru dengan token dari email. Semua sesi login lama dicabut.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword(
            $request->string('token')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::success(null, 'Password berhasil diatur ulang. Silakan masuk dengan password baru.');
    }
}
