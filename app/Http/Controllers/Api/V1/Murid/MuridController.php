<?php

namespace App\Http\Controllers\Api\V1\Murid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Murid\DaftarMuridRequest;
use App\Http\Requests\Murid\SimpanMuridRequest;
use App\Http\Requests\Murid\UbahTautanWaliRequest;
use App\Http\Resources\MuridDetailResource;
use App\Http\Resources\MuridResource;
use App\Models\Murid;
use App\Models\User;
use App\Services\KartuAkunService;
use App\Services\MuridService;
use App\Support\ApiResponse;
use App\Support\Jangkauan;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class MuridController extends Controller
{
    public function __construct(private readonly MuridService $muridService) {}

    /**
     * Daftar murid. Kepala Sekolah dan pemegang izin lihat data murid melihat semua murid, guru lain hanya murid di
     * kelas yang dia ampu pada tahun ajaran aktif, wali murid hanya anaknya sendiri.
     *
     * Filter `filter[kelas_id]`, `filter[status]`, `filter[tingkat]` (tingkat kelas di tahun ajaran aktif),
     * `filter[tahun_lulus]` (daftar alumni bersama `filter[status]=lulus`). `search` mencari nama lengkap, nama
     * panggilan, NIS, dan NISN. Urutan `sort` = `nama` | `nis` | `created_at` | `tahun_lulus`.
     */
    public function index(DaftarMuridRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $urutNama = AllowedSort::field('nama', 'nama_lengkap');

        $murid = QueryBuilder::for(Murid::query()->dataMuridUntuk($user)->with('kelasAktif')->cari($request->kataCari()), $request)
            ->allowedFilters(
                AllowedFilter::callback('kelas_id', fn (Builder $query, mixed $kelasId) => $query
                    ->whereHas('kelas', fn (Builder $kelas) => $kelas->whereKey($kelasId))),
                AllowedFilter::exact('status'),
                AllowedFilter::callback('tingkat', fn (Builder $query, mixed $tingkat) => $query
                    ->whereHas('kelasAktif', fn (Builder $kelas) => $kelas->where('tingkat', $tingkat))),
                AllowedFilter::exact('tahun_lulus'),
            )
            ->allowedSorts($urutNama, AllowedSort::field('nis'), AllowedSort::field('created_at'), AllowedSort::field('tahun_lulus'))
            ->defaultSort($urutNama)
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(MuridResource::collection($murid));
    }

    /**
     * Detail murid beserta kelas di tahun ajaran aktif, wali yang tertaut, dan riwayat status (terbaru dulu).
     */
    public function show(int $id): JsonResponse
    {
        $murid = Murid::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($murid);

        return ApiResponse::success(new MuridDetailResource($murid->muatDetail()));
    }

    /**
     * Menambah murid (status aktif). NIS dibuat otomatis dari tahun `tanggal_masuk`. Kirim sebagai
     * `multipart/form-data` jika menyertakan foto.
     *
     * Murid baru langsung dibuatkan akun wali: username NIS, password awal tanggal lahir anak (DDMMYYYY) yang
     * wajib diganti saat login pertama, hubungan `wali` sebagai kontak utama. Akun ini muncul di `wali`.
     */
    public function store(SimpanMuridRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $murid = $this->muridService->buat($request->dataMurid(), $request->foto(), $user);

        return ApiResponse::success(
            new MuridDetailResource($murid->muatDetail()),
            "{$murid->nama_lengkap} ditambahkan dengan NIS {$murid->nis}. Akun wali murid memakai NIS ini sebagai username.",
            status: 201,
        );
    }

    /**
     * Mengubah data murid. Status diubah lewat `PATCH /murid/{id}/status`; `status` dan `tanggal_keluar` di sini
     * ditolak 422 `VALIDATION_ERROR`.
     *
     * Kalau `tanggal_lahir` berubah dan akun wali otomatis murid ini belum pernah dipakai (password awal belum
     * diganti), password awalnya ikut diganti ke tanggal lahir baru (DDMMYYYY).
     */
    public function update(SimpanMuridRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $murid = $this->muridService->perbarui(Murid::query()->findOrFail($id), $request->dataMurid(), $request->foto(), $user);

        return ApiResponse::success(new MuridDetailResource($murid->muatDetail()), 'Data murid tersimpan.');
    }

    /**
     * Menghapus murid yang salah input. Murid yang sudah punya tagihan, rapor, atau data PPDB ditolak. Akun wali
     * otomatis murid ini yang belum pernah dipakai ikut dinonaktifkan.
     */
    public function destroy(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $murid = Murid::query()->findOrFail($id);
        $this->muridService->hapus($murid, $user);

        return ApiResponse::success(null, "Data {$murid->nama_lengkap} dihapus.");
    }

    /**
     * Kartu akun wali murid (PDF A6) untuk dicetak dan dibagikan: nama anak, kelas, NIS sebagai username, keterangan
     * password awal, dan alamat website. Password tidak ditulis. Ditolak 422 `BUSINESS_RULE` kalau tidak ada akun
     * wali aktif dengan username NIS ini.
     */
    #[Response(200, 'Kartu akun PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function kartuAkun(int $id, KartuAkunService $kartuAkun): HttpResponse
    {
        $murid = Murid::query()->findOrFail($id);

        return $kartuAkun->buat($murid)->download($kartuAkun->namaFile($murid));
    }

    /**
     * Mengubah hubungan (`ayah` | `ibu` | `wali`) atau kontak utama wali yang tertaut. Menjadikan wali ini kontak
     * utama melepas status itu dari wali lain; kontak utama tidak bisa dilepas tanpa memilih penggantinya.
     */
    public function ubahWali(UbahTautanWaliRequest $request, int $id, int $wali_murid_id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $murid = Murid::query()->findOrFail($id);
        $this->muridService->ubahTautanWali($murid, $wali_murid_id, $request->hubungan(), $request->kontakUtama(), $kepalaSekolah);

        return ApiResponse::success(new MuridDetailResource($murid->muatDetail()), "Data wali {$murid->nama_panggilan} tersimpan.");
    }

    /**
     * Melepas tautan wali murid dari murid ini.
     */
    public function lepasWali(int $id, int $wali_murid_id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $murid = Murid::query()->findOrFail($id);
        $this->muridService->lepasWali($murid, $wali_murid_id, $kepalaSekolah);

        return ApiResponse::success(null, "Tautan wali dilepas dari {$murid->nama_lengkap}.");
    }
}
