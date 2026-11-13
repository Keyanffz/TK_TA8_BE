<?php

namespace App\Http\Controllers\Api\V1\Absensi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsenSusulanRequest;
use App\Http\Requests\Absensi\BeriAksesSusulanRequest;
use App\Http\Resources\AbsensiResource;
use App\Http\Resources\AksesAbsenSusulanResource;
use App\Models\AksesAbsenSusulan;
use App\Models\User;
use App\Services\AbsenSusulanService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class AbsenSusulanController extends Controller
{
    public function __construct(private readonly AbsenSusulanService $susulan) {}

    /**
     * Memberi satu peserta akses absen sendiri (swafoto, tanpa pemeriksaan lokasi dan jam) untuk satu tanggal dan
     * satu jenis absen, berlaku sampai akhir hari ini. Peserta mendapat notifikasi `absensi_susulan_diberikan`.
     * Ditolak 422 `BUSINESS_RULE` kalau: untuk Kepala Sekolah sendiri, akun bukan peserta absensi, tanggal bukan hari
     * ini atau hari kerja terakhir sebelumnya, tanggal sebelum tanggal mulai, bukan hari kerja, tanggal libur, absen
     * jenis itu sudah ada (kecuali tidak hadir buatan scheduler untuk absen masuk), untuk absen pulang peserta tercatat
     * izin atau sakit, atau masih ada akses yang sama yang belum dipakai.
     */
    public function store(BeriAksesSusulanRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $akses = $this->susulan->beri($request->integer('user_id'), $request->tanggal(), $request->jenis(), $request->catatan(), $user);

        return ApiResponse::success(new AksesAbsenSusulanResource($akses), 'Akses absen susulan diberikan.', status: 201);
    }

    /**
     * Mencabut akses yang belum dipakai. Akses yang sudah dipakai → 422 `BUSINESS_RULE`.
     */
    public function destroy(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->susulan->cabut(AksesAbsenSusulan::query()->findOrFail($id), $user);

        return ApiResponse::success(null, 'Akses absen susulan dicabut.');
    }

    /**
     * Absen susulan oleh pemilik akses (`multipart/form-data`, hanya `foto`). Absen masuk berstatus `hadir`; `waktu` jam
     * server saat dikirim; koordinat kosong; `susulan` true. Tidak hadir buatan scheduler di tanggal itu diganti. Akses
     * milik peserta lain → 404. Ditolak 422 `BUSINESS_RULE` kalau akses sudah dipakai atau kedaluwarsa, tanggalnya
     * dijadikan libur, absen jenis itu sudah tercatat, atau untuk absen pulang absen masuk tanggal itu belum hadir atau
     * terlambat. Peserta mendapat notifikasi `absensi_tercatat`. Dibatasi 10 kali per menit per pengguna.
     */
    public function absen(AbsenSusulanRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $akses = AksesAbsenSusulan::query()->where('user_id', $user->id)->findOrFail($id);
        $absensi = $this->susulan->absen($akses, $user, $request->foto());

        return ApiResponse::success(new AbsensiResource($absensi), "{$absensi->namaAbsen()} tercatat pukul {$absensi->waktu?->format('H:i')}.", status: 201);
    }
}
