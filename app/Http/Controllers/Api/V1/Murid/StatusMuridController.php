<?php

namespace App\Http\Controllers\Api\V1\Murid;

use App\Enums\StatusMurid;
use App\Http\Controllers\Controller;
use App\Http\Requests\Murid\UbahStatusMassalRequest;
use App\Http\Requests\Murid\UbahStatusMuridRequest;
use App\Http\Resources\MuridDetailResource;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\User;
use App\Services\StatusMuridService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Status murid, kelulusan, dan alumni (A2.14).
 */
class StatusMuridController extends Controller
{
    public function __construct(private readonly StatusMuridService $statusMurid) {}

    /**
     * Mengubah status satu murid dan mencatatnya di `riwayat_status`. Lulus, pindah, dan keluar menutup penempatan
     * kelas di tahun ajaran aktif dan menghentikan tagihan bulanan berikutnya; tagihan lama tetap tercatat. Kembali
     * aktif membuka lagi penempatannya (ditolak 422 `BUSINESS_RULE` kalau kelasnya penuh). Status yang sama, tanggal
     * sebelum tanggal masuk, atau tahun lulus sebelum tahun masuk juga ditolak 422 `BUSINESS_RULE`.
     */
    public function ubah(UbahStatusMuridRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $murid = $this->statusMurid->ubah(Murid::query()->findOrFail($id), $request->perubahan(), $user);

        return ApiResponse::success(new MuridDetailResource($murid->muatDetail()), "Status {$murid->nama_lengkap} sekarang {$murid->status->label()}.");
    }

    /**
     * Mengubah status banyak murid sekaligus dalam satu transaksi: murid terpilih (`murid_ids`) atau semua murid aktif
     * satu kelas (`kelas_id`, misalnya meluluskan TK B1 dengan `status: lulus` dan `tahun_lulus`). Murid yang statusnya
     * sudah sama dilewati. Satu murid yang melanggar aturan membatalkan seluruh perubahan (422 `BUSINESS_RULE`).
     *
     * @response array{success: true, message: string, data: array{diubah: int, dilewati: int}, meta: null}
     */
    public function massal(UbahStatusMassalRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $perubahan = $request->perubahan();
        $muridIds = $request->muridIds() ?? $this->statusMurid->muridAktifDiKelas(Kelas::query()->findOrFail($request->integer('kelas_id')));
        $hasil = $this->statusMurid->ubahMassal($muridIds, $perubahan, $user);

        return ApiResponse::success(
            $hasil,
            "{$hasil['diubah']} murid sekarang berstatus {$perubahan['status']->label()}, {$hasil['dilewati']} dilewati karena statusnya sudah sama.",
        );
    }

    /**
     * Tahun lulus yang punya alumni, terbaru dulu, untuk pilihan saringan daftar alumni
     * (`GET /murid?filter[status]=lulus&filter[tahun_lulus]=`).
     *
     * @response array{success: true, message: string, data: list<int>, meta: null}
     */
    public function tahunLulus(): JsonResponse
    {
        return ApiResponse::success(Murid::query()
            ->where('status', StatusMurid::Lulus)
            ->whereNotNull('tahun_lulus')
            ->distinct()
            ->orderByDesc('tahun_lulus')
            ->pluck('tahun_lulus')
            ->map(fn (mixed $tahun): int => (int) $tahun)
            ->all());
    }
}
