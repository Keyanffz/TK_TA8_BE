<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegistrasiGuruRequest;
use App\Services\GuruService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RegistrasiGuruController extends Controller
{
    /**
     * Pendaftaran akun guru. Akun berstatus `pending` sampai disetujui Kepala Sekolah.
     */
    public function __invoke(RegistrasiGuruRequest $request, GuruService $guru): JsonResponse
    {
        $guru->daftar($request->validated());

        return ApiResponse::success(
            null,
            'Pendaftaran terkirim. Akun Anda menunggu persetujuan Kepala Sekolah; kami kirim email setelah disetujui.',
            status: 201,
        );
    }
}
