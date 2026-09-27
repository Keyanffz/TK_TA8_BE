<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GantiPasswordRequest;
use App\Http\Requests\Auth\PerbaruiProfilRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class ProfilController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Mengubah nama, nomor HP, dan foto profil. Kirim sebagai `multipart/form-data`.
     */
    public function perbarui(PerbaruiProfilRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->auth->perbaruiProfil($user, $request->validated(), $request->avatar());

        return ApiResponse::success(new UserResource($user), 'Profil tersimpan.');
    }

    /**
     * Mengganti password. Sesi login di perangkat lain dicabut; sesi ini tetap berlaku.
     *
     * Untuk wali murid, password baru tidak boleh sama dengan tanggal lahir anak (DDMMYYYY), dan
     * `wajib_ganti_password` menjadi `false` setelah berhasil.
     */
    public function gantiPassword(GantiPasswordRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->auth->gantiPassword($user, $request->string('password')->toString());

        return ApiResponse::success(null, 'Password berhasil diganti. Sesi di perangkat lain sudah dikeluarkan.');
    }
}
