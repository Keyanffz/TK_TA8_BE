<?php

namespace App\Http\Controllers\Api\V1\Wali;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wali\TautkanAnakRequest;
use App\Http\Resources\AnakWaliResource;
use App\Models\User;
use App\Services\KodeTautanService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class AnakController extends Controller
{
    /**
     * Anak yang tertaut ke akun wali murid yang sedang login.
     */
    public function index(#[CurrentUser] User $user): JsonResponse
    {
        $anak = $user->profilWaliMurid()->murid()->with('kelasAktif')->orderBy('nama_panggilan')->get();

        return ApiResponse::success(AnakWaliResource::collection($anak));
    }

    /**
     * Menautkan anak dengan kode tautan dari sekolah dan tanggal lahir anak.
     *
     * Dibatasi 5 percobaan per menit. Kode salah, kedaluwarsa, atau tanggal lahir yang tidak cocok
     * dibalas 422 `VALIDATION_ERROR` pada field `kode` / `tanggal_lahir`.
     */
    public function tautkan(TautkanAnakRequest $request, #[CurrentUser] User $user, KodeTautanService $kodeTautan): JsonResponse
    {
        $wali = $user->profilWaliMurid();
        $murid = $kodeTautan->tautkan($wali, $request->string('kode')->toString(), $request->tanggalLahir(), $request->hubungan());
        $anak = $wali->murid()->with('kelasAktif')->findOrFail($murid->id);

        return ApiResponse::success(new AnakWaliResource($anak), "{$anak->nama_panggilan} berhasil ditautkan ke akun Anda.");
    }
}
