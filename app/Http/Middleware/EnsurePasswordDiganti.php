<?php

namespace App\Http\Middleware;

use App\Enums\KodeError;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun wali yang masih memakai password awal (tanggal lahir anak) hanya boleh membuka `/auth/me`, mengganti
 * password, dan keluar. Route itu sengaja tidak memakai middleware ini.
 */
class EnsurePasswordDiganti
{
    public const PESAN = 'Ganti password awal Anda terlebih dahulu sebelum memakai fitur lain.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->wajib_ganti_password) {
            return ApiResponse::error(self::PESAN, KodeError::PasswordWajibDiganti);
        }

        return $next($request);
    }
}
