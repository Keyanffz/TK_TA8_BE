<?php

namespace App\Http\Controllers\Api\V1\Kelas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kelas\KenaikanKelasRequest;
use App\Http\Requests\Kelas\TempatkanMuridRequest;
use App\Http\Resources\KelasResource;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Services\KelasService;
use App\Services\KenaikanKelasService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PenempatanMuridController extends Controller
{
    /**
     * Menempatkan murid aktif ke kelas. Ditolak jika melebihi kapasitas atau murid sudah punya kelas
     * di tahun ajaran yang sama.
     */
    public function tempatkan(TempatkanMuridRequest $request, int $id, KelasService $kelasService): JsonResponse
    {
        $kelas = $kelasService->tempatkanMurid(Kelas::query()->findOrFail($id), $request->muridIds());

        return ApiResponse::success(new KelasResource($kelas->muatDetail()), count($request->muridIds())." murid ditempatkan di {$kelas->nama}.");
    }

    /**
     * Mengeluarkan murid dari kelas untuk memperbaiki salah penempatan. Ditolak kalau murid sudah punya rapor
     * di kelas itu. Murid yang keluar sekolah diubah statusnya lewat `PUT /murid/{id}`.
     */
    public function keluarkan(int $id, int $murid_id, KelasService $kelasService): JsonResponse
    {
        $kelas = Kelas::query()->findOrFail($id);
        $kelasService->keluarkanMurid($kelas, $murid_id);

        return ApiResponse::success(null, "Murid dikeluarkan dari {$kelas->nama}.");
    }

    /**
     * Kenaikan kelas massal dari tahun ajaran aktif ke tahun ajaran tujuan, dalam satu transaksi.
     *
     * Murid `naik`/`tinggal` wajib punya `kelas_tujuan_id` di tahun ajaran tujuan; murid `lulus` tanpa kelas
     * tujuan dan statusnya menjadi lulus.
     */
    public function kenaikan(KenaikanKelasRequest $request, KenaikanKelasService $kenaikanKelas): JsonResponse
    {
        $tujuan = TahunAjaran::query()->findOrFail($request->integer('tahun_ajaran_tujuan_id'));
        $hasil = $kenaikanKelas->proses($tujuan, $request->penempatan());

        return ApiResponse::success(
            $hasil,
            "Kenaikan kelas ke {$tujuan->nama} tersimpan: {$hasil['naik']} naik, {$hasil['tinggal']} tinggal kelas, {$hasil['lulus']} lulus.",
        );
    }
}
