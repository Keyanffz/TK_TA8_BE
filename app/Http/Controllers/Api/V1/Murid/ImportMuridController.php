<?php

namespace App\Http\Controllers\Api\V1\Murid;

use App\Exports\TemplateImportMuridExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Murid\ImportMuridRequest;
use App\Models\User;
use App\Services\ImportMuridService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Import murid massal dari Excel/CSV (A2.14): unduh template, pratinjau per baris, lalu simpan baris yang valid.
 */
class ImportMuridController extends Controller
{
    public function __construct(private readonly ImportMuridService $importMurid) {}

    /**
     * Template .xlsx: sheet Murid berisi judul kolom, sheet Petunjuk berisi aturan setiap kolom. Kolom NISN, NIK, dan
     * tanggal berformat teks.
     */
    #[Response(200, 'Template import murid (.xlsx)', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function template(): BinaryFileResponse
    {
        return Excel::download(new TemplateImportMuridExport, 'template-import-murid.xlsx');
    }

    /**
     * Memeriksa setiap baris file tanpa menyimpan apa pun. `nomor` = nomor baris di spreadsheet (judul kolom di baris
     * 1). `data` berisi isi baris setelah dirapikan (tanggal menjadi YYYY-MM-DD, status kosong menjadi `aktif`).
     * Kolom wajib yang tidak ada, file kosong, file tidak terbaca, atau lebih dari 500 baris ditolak 422
     * `VALIDATION_ERROR` di field `file`.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         ringkasan: array{total: int, valid: int, invalid: int},
     *         baris: list<array{
     *             nomor: int,
     *             data: array{nama_lengkap: string|null, nama_panggilan: string|null, nisn: string|null, nik: string|null, jenis_kelamin: string|null, tempat_lahir: string|null, tanggal_lahir: string|null, agama: string|null, alamat: string|null, anak_ke: string|null, catatan_khusus: string|null, tanggal_masuk: string|null, status: string, tanggal_status: string|null, tahun_lulus: string|null, sekolah_tujuan: string|null, keterangan: string|null, kelas: string|null},
     *             valid: bool,
     *             alasan: list<string>
     *         }>
     *     },
     *     meta: null
     * }
     */
    public function pratinjau(ImportMuridRequest $request): JsonResponse
    {
        $hasil = $this->importMurid->pratinjau($request->berkas());

        return ApiResponse::success($hasil, "{$hasil['ringkasan']['valid']} dari {$hasil['ringkasan']['total']} baris siap diimpor.");
    }

    /**
     * Memeriksa ulang file yang sama, lalu menyimpan semua baris valid dalam satu transaksi; baris invalid dilewati
     * dan dikembalikan di `gagal` dengan bentuk yang sama seperti pratinjau. Murid aktif dibuatkan akun wali otomatis
     * (username NIS, password awal tanggal lahir DDMMYYYY, wajib diganti) dan ditempatkan di `kelas` kalau diisi.
     * Murid lulus, pindah, dan keluar disimpan tanpa akun wali dan tidak ikut generate tagihan. Dicatat di log
     * aktivitas `murid` (event `diimpor`).
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         dibuat: int,
     *         akun_wali_dibuat: int,
     *         gagal: list<array{
     *             nomor: int,
     *             data: array{nama_lengkap: string|null, nama_panggilan: string|null, nisn: string|null, nik: string|null, jenis_kelamin: string|null, tempat_lahir: string|null, tanggal_lahir: string|null, agama: string|null, alamat: string|null, anak_ke: string|null, catatan_khusus: string|null, tanggal_masuk: string|null, status: string, tanggal_status: string|null, tahun_lulus: string|null, sekolah_tujuan: string|null, keterangan: string|null, kelas: string|null},
     *             valid: bool,
     *             alasan: list<string>
     *         }>
     *     },
     *     meta: null
     * }
     */
    public function simpan(ImportMuridRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $hasil = $this->importMurid->simpan($request->berkas(), $user);
        $gagal = count($hasil['gagal']);

        return ApiResponse::success($hasil, "{$hasil['dibuat']} murid diimpor".($gagal === 0 ? '.' : ", {$gagal} baris gagal."));
    }
}
