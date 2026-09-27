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
     * Onboarding dan ubah profil wali murid setelah password awal diganti. `nama` dan `no_hp` wajib; `nik`,
     * `alamat`, dan `pekerjaan` opsional (tidak dikirim = tidak berubah, `null` = dikosongkan).
     * `wali_murid.profil_lengkap` bernilai `true` setelah nomor HP, alamat, dan pekerjaan terisi.
     */
    public function __invoke(LengkapiProfilWaliRequest $request, #[CurrentUser] User $user, WaliMuridService $service): JsonResponse
    {
        $service->lengkapiProfil($user, $request->dataWali());

        return ApiResponse::success(new UserResource($user), 'Profil tersimpan.');
    }
}
