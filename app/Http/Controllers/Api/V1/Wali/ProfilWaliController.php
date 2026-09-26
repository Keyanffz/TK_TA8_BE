<?php

namespace App\Http\Controllers\Api\V1\Wali;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wali\LengkapiProfilWaliRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\WaliMuridService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class ProfilWaliController extends Controller
{
    /**
     * Onboarding wali murid: melengkapi nomor HP, alamat, pekerjaan, dan NIK (opsional).
     * Setelah berhasil, `wali_murid.profil_lengkap` bernilai `true`.
     */
    public function __invoke(LengkapiProfilWaliRequest $request, #[CurrentUser] User $user, WaliMuridService $service): JsonResponse
    {
        $service->lengkapiProfil($user, $request->validated());

        return ApiResponse::success(new UserResource($user), 'Profil tersimpan.');
    }
}
