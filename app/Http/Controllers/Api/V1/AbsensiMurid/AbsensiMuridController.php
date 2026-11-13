<?php

namespace App\Http\Controllers\Api\V1\AbsensiMurid;

use App\Exports\RekapAbsensiMuridExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AbsensiMurid\HarianAbsensiMuridRequest;
use App\Http\Requests\AbsensiMurid\RekapAbsensiMuridRequest;
use App\Http\Requests\AbsensiMurid\SimpanAbsensiMuridRequest;
use App\Models\Kelas;
use App\Models\User;
use App\Services\AbsensiMuridService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Absensi murid per kelas: wali kelas dan guru pendamping kelas itu di tahun ajaran aktif, serta Kepala Sekolah.
 * Guru lain (termasuk pemegang izin data murid) dibalas 404; wali murid 403.
 */
class AbsensiMuridController extends Controller
{
    public function __construct(private readonly AbsensiMuridService $absensi) {}

    /**
     * Murid aktif kelas itu beserta status pada tanggal yang dipilih (bawaan hari ini). `status` null kalau belum
     * dicatat. `bisa_dicatat` false dengan `alasan` untuk tanggal yang belum tiba, bukan hari sekolah kelas itu, termasuk
     * libur kelas itu, atau di luar tahun ajaran kelas.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         tanggal: string,
     *         bisa_dicatat: bool,
     *         alasan: string|null,
     *         murid: list<array{murid: array{id: int, nis: string, nama_lengkap: string}, status: \App\Enums\StatusAbsensiMurid|null, keterangan: string|null}>
     *     },
     *     meta: null
     * }
     */
    public function index(HarianAbsensiMuridRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::success($this->absensi->harian(Kelas::untukPengampu($id, $user), $request->tanggal()));
    }

    /**
     * Menyimpan absensi semua murid aktif kelas itu untuk satu tanggal dalam satu transaksi (dibuat atau diperbarui).
     * Ditolak 422 `BUSINESS_RULE` kalau tanggal tidak bisa dicatat, ada murid aktif yang tidak dikirim, atau ada murid
     * yang bukan murid aktif kelas itu. Membalas bentuk yang sama dengan `GET`.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         tanggal: string,
     *         bisa_dicatat: bool,
     *         alasan: string|null,
     *         murid: list<array{murid: array{id: int, nis: string, nama_lengkap: string}, status: \App\Enums\StatusAbsensiMurid|null, keterangan: string|null}>
     *     },
     *     meta: null
     * }
     */
    public function update(SimpanAbsensiMuridRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $kelas = Kelas::untukPengampu($id, $user);
        $this->absensi->simpan($kelas, $request->tanggal(), $request->absensi(), $user);

        return ApiResponse::success(
            $this->absensi->harian($kelas, $request->tanggal()),
            "Absensi {$kelas->nama} tanggal {$request->tanggal()->translatedFormat('j F Y')} disimpan.",
        );
    }

    /**
     * Jumlah hadir, izin, sakit, dan alpa per murid dalam satu bulan (bawaan bulan berjalan), dan jumlah hari yang
     * sudah dicatat. Absensi pada tanggal yang sekarang libur kelas atau bukan hari sekolah kelas tidak dihitung.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         bulan: string,
     *         hari_tercatat: int,
     *         murid: list<array{murid: array{id: int, nis: string, nama_lengkap: string}, hadir: int, izin: int, sakit: int, alpa: int}>
     *     },
     *     meta: null
     * }
     */
    public function rekap(RekapAbsensiMuridRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::success($this->absensi->rekap(Kelas::untukPengampu($id, $user), $request->bulan()));
    }

    /**
     * Rekap bulanan sebagai file Excel (`format=xlsx`, bawaan) atau PDF berkop sekolah (`format=pdf`), misalnya
     * `absensi-murid-tk-a1-2026-10.xlsx`.
     */
    #[Response(200, 'Rekap absensi murid (.xlsx)', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    #[Response(200, 'Rekap absensi murid (.pdf)', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function export(RekapAbsensiMuridRequest $request, int $id, #[CurrentUser] User $user): HttpResponse|BinaryFileResponse
    {
        $kelas = Kelas::untukPengampu($id, $user);
        $bulan = $request->bulan();
        $namaFile = $this->absensi->namaFile($kelas, $bulan).'.'.$request->formatFile();

        return $request->formatFile() === 'pdf'
            ? $this->absensi->pdf($kelas, $bulan)->download($namaFile)
            : Excel::download(new RekapAbsensiMuridExport($this->absensi->rekap($kelas, $bulan)['murid']), $namaFile);
    }
}
