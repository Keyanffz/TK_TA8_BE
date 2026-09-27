<?php

namespace App\Http\Controllers\Api\V1\Ppdb;

use App\Http\Controllers\Controller;
use App\Http\Requests\AlasanRequest;
use App\Http\Requests\Pendaftaran\BuatPendaftaranRequest;
use App\Http\Requests\Pendaftaran\DaftarPendaftaranRequest;
use App\Http\Requests\Pendaftaran\StatusPendaftaranPublikRequest;
use App\Http\Requests\Pendaftaran\TerimaPendaftaranRequest;
use App\Http\Resources\PendaftaranDetailResource;
use App\Http\Resources\PendaftaranPublikResource;
use App\Http\Resources\PendaftaranResource;
use App\Models\Pendaftaran;
use App\Models\User;
use App\Services\PendaftaranService;
use App\Support\ApiResponse;
use App\Support\Jangkauan;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class PendaftaranController extends Controller
{
    private const RELASI_DETAIL = ['tahunAjaran', 'waliMurid.user', 'dokumen', 'murid'];

    public function __construct(private readonly PendaftaranService $pendaftaranService) {}

    /**
     * Daftar pendaftaran PPDB. Kepala Sekolah melihat semua, wali murid hanya miliknya.
     *
     * Filter `filter[status]`, `filter[tahun_ajaran_id]`, `filter[tingkat_tujuan]`. `search` mencari kode dan nama
     * anak. Urutan `sort` = `created_at` | `nama` (bawaan `-created_at`).
     */
    public function index(DaftarPendaftaranRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $kata = $request->kataCari();
        $query = Pendaftaran::query()->visibleTo($user)->with(['tahunAjaran', 'murid'])
            ->when($kata !== null, fn (Builder $query) => $query->where(fn (Builder $cari) => $cari
                ->where('kode', 'like', "%{$kata}%")
                ->orWhere('nama_lengkap', 'like', "%{$kata}%")));

        $pendaftaran = QueryBuilder::for($query, $request)
            ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('tahun_ajaran_id'), AllowedFilter::exact('tingkat_tujuan'))
            ->allowedSorts(AllowedSort::field('created_at'), AllowedSort::field('nama', 'nama_lengkap'))
            ->defaultSort('-created_at', '-id')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(PendaftaranResource::collection($pendaftaran));
    }

    /**
     * Detail pendaftaran beserta dokumen dan data wali pendaftar.
     */
    public function show(int $id): JsonResponse
    {
        $pendaftaran = Pendaftaran::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($pendaftaran);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)));
    }

    /**
     * Wali yang sudah login mendaftarkan kakak/adik (`multipart/form-data`) untuk tahun ajaran PPDB di pengaturan.
     * Ditolak kalau PPDB ditutup, kuota penuh, atau NIK anak sudah punya pendaftaran selain ditolak atau sudah
     * menjadi murid. Anak yang diterima ditautkan ke akun ini.
     */
    public function store(BuatPendaftaranRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->daftar($user->profilWaliMurid(), $request->dataPendaftaran(), $request->dokumen());

        return ApiResponse::success(
            new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)),
            "Pendaftaran {$pendaftaran->nama_panggilan} terkirim dengan kode {$pendaftaran->kode}. Pantau statusnya di menu PPDB.",
            status: 201,
        );
    }

    /**
     * Pendaftaran PPDB tanpa login (`multipart/form-data`), dengan isian dan aturan yang sama seperti
     * `POST /pendaftaran`. Dibatasi 3 pendaftaran per jam per IP. Simpan `kode` untuk mengecek status lewat
     * `GET /public/pendaftaran/status`. Kalau diterima, sekolah membuatkan akun wali dengan username NIS anak.
     */
    public function storePublik(BuatPendaftaranRequest $request): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->daftar(null, $request->dataPendaftaran(), $request->dokumen());

        return ApiResponse::success(
            new PendaftaranPublikResource($pendaftaran->load('tahunAjaran')),
            "Pendaftaran {$pendaftaran->nama_panggilan} terkirim dengan kode {$pendaftaran->kode}. Simpan kode ini untuk mengecek status pendaftaran.",
            status: 201,
        );
    }

    /**
     * Status pendaftaran tanpa login dari kode pendaftaran dan tanggal lahir anak. Kode atau tanggal lahir yang
     * tidak cocok dibalas 404. Dibatasi 10 percobaan per menit per IP.
     */
    public function statusPublik(StatusPendaftaranPublikRequest $request): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->cariUntukPublik($request->string('kode')->toString(), $request->tanggalLahir())
            ?? throw new ModelNotFoundException;

        return ApiResponse::success(new PendaftaranPublikResource($pendaftaran));
    }

    /**
     * Menandai dokumen pendaftaran sudah diperiksa dan lengkap.
     */
    public function verifikasi(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->verifikasi(Pendaftaran::query()->findOrFail($id), $user);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)), "Pendaftaran {$pendaftaran->kode} diverifikasi.");
    }

    /**
     * Menerima pendaftar yang sudah diverifikasi: membuat data murid, menautkannya ke wali pendaftar, dan
     * menempatkannya di kelas kalau `kelas_id` dikirim.
     */
    public function terima(TerimaPendaftaranRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->terima(Pendaftaran::query()->findOrFail($id), $request->kelas(), $user);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)), "{$pendaftaran->nama_lengkap} diterima sebagai murid.");
    }

    public function tolak(AlasanRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->tolak(Pendaftaran::query()->findOrFail($id), $request->alasan(), $user);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)), "Pendaftaran {$pendaftaran->kode} ditolak.");
    }
}
