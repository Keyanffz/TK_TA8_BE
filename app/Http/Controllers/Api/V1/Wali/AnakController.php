<?php

namespace App\Http\Controllers\Api\V1\Wali;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wali\LengkapiDataAnakRequest;
use App\Http\Requests\Wali\TambahAnakRequest;
use App\Http\Resources\AnakWaliResource;
use App\Http\Resources\MuridDetailResource;
use App\Models\User;
use App\Services\MuridService;
use App\Services\WaliMuridService;
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
     * Menambahkan kakak/adik ke akun wali yang sedang login dengan NIS dan tanggal lahir anak.
     *
     * Dibatasi 5 percobaan per menit per akun. NIS atau tanggal lahir yang tidak cocok dibalas 422
     * `VALIDATION_ERROR` pada field `nis`. Akun wali otomatis anak itu yang belum pernah dipakai (password awal
     * belum diganti) dinonaktifkan dan tautannya dilepas; akun yang sudah dipakai tetap tertaut, sehingga anak
     * punya lebih dari satu akun wali.
     */
    public function tambah(TambahAnakRequest $request, #[CurrentUser] User $user, WaliMuridService $service): JsonResponse
    {
        $wali = $user->profilWaliMurid()->setRelation('user', $user);
        $murid = $service->tambahAnak($wali, $request->string('nis')->toString(), $request->tanggalLahir(), $request->hubungan());
        $anak = $wali->murid()->with('kelasAktif')->findOrFail($murid->id);

        return ApiResponse::success(new AnakWaliResource($anak), "{$anak->nama_panggilan} berhasil ditambahkan ke akun Anda.");
    }

    /**
     * Wali melengkapi isian formulir data anaknya yang masih kosong, termasuk NIK. Isian yang sudah terisi tidak bisa
     * diubah: nilai lain untuk isian itu dibalas 422 `VALIDATION_ERROR` di field tersebut, dan perubahan dilakukan
     * lewat sekolah. Anak yang tidak tertaut ke akun ini dibalas 404.
     */
    public function lengkapi(LengkapiDataAnakRequest $request, int $id, #[CurrentUser] User $user, MuridService $service): JsonResponse
    {
        $murid = $service->lengkapi($user->profilWaliMurid()->murid()->findOrFail($id), $request->validated(), $user);

        return ApiResponse::success(new MuridDetailResource($murid->muatDetail()), "Data {$murid->nama_panggilan} tersimpan.");
    }
}
