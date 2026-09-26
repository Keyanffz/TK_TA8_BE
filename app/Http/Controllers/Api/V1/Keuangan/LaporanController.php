<?php

namespace App\Http\Controllers\Api\V1\Keuangan;

use App\Exports\LaporanKeuanganExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laporan\LaporanKeuanganRequest;
use App\Http\Requests\Laporan\LaporanTunggakanRequest;
use App\Services\LaporanKeuanganService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LaporanController extends Controller
{
    public function __construct(private readonly LaporanKeuanganService $laporan) {}

    /**
     * Ringkasan keuangan dalam rentang `dari`–`sampai` (tagihan menurut jatuh tempo, pemasukan menurut tanggal
     * bayar), rincian per jenis tagihan, dan per bulan. `kelas_id` opsional.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         dari: string,
     *         sampai: string,
     *         ringkasan: array{jumlah_tagihan: int, total_tagihan: int, terbayar: int, belum_terbayar: int, persen_lunas: float, pemasukan: int},
     *         per_jenis: list<array{jenis_tagihan: array{id: int, nama: string}, jumlah_tagihan: int, total_tagihan: int, terbayar: int, belum_terbayar: int, persen_lunas: float}>,
     *         per_bulan: list<array{bulan: string, jumlah_tagihan: int, total_tagihan: int, terbayar: int, belum_terbayar: int, persen_lunas: float, pemasukan: int}>
     *     },
     *     meta: null
     * }
     */
    public function keuangan(LaporanKeuanganRequest $request): JsonResponse
    {
        return ApiResponse::success($this->laporan->ringkasan($request->dari(), $request->sampai(), $request->kelasId()));
    }

    /**
     * File Excel berisi rincian tagihan dan pembayaran diterima dalam rentang `dari`–`sampai`.
     */
    #[Response(200, 'Laporan keuangan (.xlsx)', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function export(LaporanKeuanganRequest $request): BinaryFileResponse
    {
        $namaFile = "laporan-keuangan-{$request->dari()->toDateString()}-sd-{$request->sampai()->toDateString()}.xlsx";

        return Excel::download(new LaporanKeuanganExport($request->dari(), $request->sampai()), $namaFile);
    }

    /**
     * Murid yang punya tagihan terlambat, urut total tunggakan terbesar, beserta kontak utama walinya.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         total_tunggakan: int,
     *         jumlah_murid: int,
     *         murid: list<array{
     *             id: int, nis: string, nama_lengkap: string, kelas: array{id: int, nama: string}|null,
     *             kontak_wali: array{nama: string, no_hp: string|null}|null, jumlah_tagihan: int, total: int,
     *             tagihan: list<array{id: int, kode: string, nama: string, jatuh_tempo: string, total: int}>
     *         }>
     *     },
     *     meta: null
     * }
     */
    public function tunggakan(LaporanTunggakanRequest $request): JsonResponse
    {
        return ApiResponse::success($this->laporan->tunggakan($request->kelasId()));
    }
}
