<?php

namespace App\Http\Controllers\Api\V1\Murid;

use App\Exports\TemplateNisMuridExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Murid\BatchNisMuridRequest;
use App\Http\Requests\Murid\ImportNisMuridRequest;
use App\Http\Requests\Murid\IsiNisMuridRequest;
use App\Http\Resources\MuridDetailResource;
use App\Models\Murid;
use App\Models\User;
use App\Services\NisMuridService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * NIS murid diisi Kepala Sekolah (A2.1): satu murid, banyak murid dari tabel web, atau file Excel. Murid yang menunggu
 * NIS didaftar lewat `GET /murid?filter[menunggu_nis]=true`.
 */
class NisMuridController extends Controller
{
    public function __construct(private readonly NisMuridService $nisMurid) {}

    /**
     * Mengisi atau mengubah NIS satu murid. NIS disimpan huruf besar tanpa spasi: huruf, angka, titik, strip, dan
     * garis miring, maksimal 20 karakter, unik di antara NIS murid (termasuk yang dihapus) dan username akun.
     * Mengisi NIS murid aktif yang belum punya wali membuat akun wali otomatis (username NIS, password awal tanggal
     * lahir DDMMYYYY, wajib diganti); murid dari PPDB tanpa login memakai hubungan dan nomor HP formulirnya. Mengubah
     * NIS ikut mengganti username akun yang memakai NIS lama, dan akun itu mendapat notifikasi `username_diubah`.
     * Pelanggaran aturan dibalas 422 `VALIDATION_ERROR` di field `nis`.
     */
    public function isi(IsiNisMuridRequest $request, int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $murid = Murid::query()->findOrFail($id);
        $nisLama = $murid->nis;
        $akunDibuat = $this->nisMurid->isi($murid, $request->nis(), $kepalaSekolah);
        $murid->refresh();

        $pesan = match (true) {
            $nisLama !== null => "NIS {$murid->nama_lengkap} diubah dari {$nisLama} menjadi {$murid->nis}.",
            $akunDibuat => "NIS {$murid->nis} tersimpan untuk {$murid->nama_lengkap}. Akun wali dibuat dengan username {$murid->nis}; kartu akunnya bisa diunduh.",
            default => "NIS {$murid->nis} tersimpan untuk {$murid->nama_lengkap}.",
        };

        return ApiResponse::success(new MuridDetailResource($murid->muatDetail()), $pesan);
    }

    /**
     * File .xlsx berisi murid aktif yang menunggu NIS (kolom `id`, `nama_lengkap`, `tanggal_lahir`, dan `nis` kosong
     * berformat teks), urut nama, untuk diisi lalu diunggah ke `POST /murid/nis/import`.
     */
    #[Response(200, 'Daftar murid menunggu NIS (.xlsx)', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function template(): BinaryFileResponse
    {
        return Excel::download(new TemplateNisMuridExport($this->nisMurid->menungguNis()), 'murid-menunggu-nis.xlsx');
    }

    /**
     * Memeriksa NIS dari file tanpa menyimpan. `nomor` = nomor baris di spreadsheet. Baris invalid membawa `alasan`:
     * NIS kosong atau formatnya salah, NIS dobel di file (semua baris yang dobel), NIS sudah dipakai murid lain atau
     * username akun lain, murid tidak ditemukan atau sudah punya NIS, dan nama yang tidak sama dengan murid ber-ID itu
     * (dibandingkan tanpa beda huruf besar dan spasi berlebih). `akun_wali` true untuk baris yang akan membuat akun
     * wali. Kolom wajib yang tidak ada, file kosong, file tidak terbaca, atau lebih dari 500 baris ditolak 422
     * `VALIDATION_ERROR` di field `file`.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         ringkasan: array{total: int, valid: int, invalid: int, akun_wali: int},
     *         baris: list<array{nomor: int, murid_id: int|null, nama_lengkap: string|null, nis: string|null, valid: bool, akun_wali: bool, alasan: list<string>}>
     *     },
     *     meta: null
     * }
     */
    public function pratinjauImport(ImportNisMuridRequest $request): JsonResponse
    {
        return $this->pratinjau($this->nisMurid->pratinjau($this->nisMurid->baca($request->berkas())));
    }

    /**
     * Memeriksa ulang file yang sama, lalu mengisi NIS semua baris valid dalam satu transaksi; baris invalid
     * dikembalikan di `gagal` dengan bentuk yang sama seperti pratinjau. Kalau NIS bentrok dengan data yang baru
     * tersimpan saat proses berjalan, tidak ada yang disimpan (422 `BUSINESS_RULE`).
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         diisi: int,
     *         akun_wali_dibuat: int,
     *         gagal: list<array{nomor: int, murid_id: int|null, nama_lengkap: string|null, nis: string|null, valid: bool, akun_wali: bool, alasan: list<string>}>
     *     },
     *     meta: null
     * }
     */
    public function simpanImport(ImportNisMuridRequest $request, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        return $this->simpan($this->nisMurid->simpan($this->nisMurid->baca($request->berkas()), $kepalaSekolah));
    }

    /**
     * Sama dengan pratinjau import untuk NIS yang diketik di tabel web. `nomor` = urutan item mulai 1; baris kosong
     * sebaiknya tidak dikirim.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         ringkasan: array{total: int, valid: int, invalid: int, akun_wali: int},
     *         baris: list<array{nomor: int, murid_id: int|null, nama_lengkap: string|null, nis: string|null, valid: bool, akun_wali: bool, alasan: list<string>}>
     *     },
     *     meta: null
     * }
     */
    public function pratinjauBatch(BatchNisMuridRequest $request): JsonResponse
    {
        return $this->pratinjau($this->nisMurid->pratinjau($request->baris()));
    }

    /**
     * Sama dengan simpan import untuk NIS yang diketik di tabel web.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         diisi: int,
     *         akun_wali_dibuat: int,
     *         gagal: list<array{nomor: int, murid_id: int|null, nama_lengkap: string|null, nis: string|null, valid: bool, akun_wali: bool, alasan: list<string>}>
     *     },
     *     meta: null
     * }
     */
    public function simpanBatch(BatchNisMuridRequest $request, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        return $this->simpan($this->nisMurid->simpan($request->baris(), $kepalaSekolah));
    }

    /**
     * @param  array{ringkasan: array{total: int, valid: int, invalid: int, akun_wali: int}, baris: list<array<string, mixed>>}  $hasil
     */
    private function pratinjau(array $hasil): JsonResponse
    {
        $ringkasan = $hasil['ringkasan'];

        return ApiResponse::success($hasil, "{$ringkasan['valid']} dari {$ringkasan['total']} baris siap disimpan, {$ringkasan['akun_wali']} akun wali akan dibuat.");
    }

    /**
     * @param  array{diisi: int, akun_wali_dibuat: int, gagal: list<array<string, mixed>>}  $hasil
     */
    private function simpan(array $hasil): JsonResponse
    {
        $gagal = count($hasil['gagal']);

        return ApiResponse::success($hasil, "{$hasil['diisi']} NIS tersimpan, {$hasil['akun_wali_dibuat']} akun wali dibuat".($gagal === 0 ? '.' : ", {$gagal} baris gagal."));
    }
}
