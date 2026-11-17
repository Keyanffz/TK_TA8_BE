<?php

namespace App\Http\Controllers\Api\V1\Ppdb;

use App\Enums\Role;
use App\Enums\StatusPendaftaran;
use App\Http\Controllers\Controller;
use App\Http\Requests\AlasanRequest;
use App\Http\Requests\BerkasFisikRequest;
use App\Http\Requests\Pendaftaran\BuatPendaftaranRequest;
use App\Http\Requests\Pendaftaran\DaftarPendaftaranRequest;
use App\Http\Requests\Pendaftaran\MundurPendaftaranRequest;
use App\Http\Requests\Pendaftaran\StatusPendaftaranPublikRequest;
use App\Http\Requests\Pendaftaran\TerimaPendaftaranRequest;
use App\Http\Resources\PendaftaranDetailResource;
use App\Http\Resources\PendaftaranPublikResource;
use App\Http\Resources\PendaftaranResource;
use App\Models\Pendaftaran;
use App\Models\User;
use App\Services\BerkasFisikService;
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
    private const RELASI_DETAIL = ['tahunAjaran', 'waliMurid.user', 'murid', 'orangTua', 'kkDiterimaOleh', 'aktaDiterimaOleh'];

    public function __construct(
        private readonly PendaftaranService $pendaftaranService,
        private readonly BerkasFisikService $berkasFisik,
    ) {}

    /**
     * Daftar pendaftaran PPDB. Kepala Sekolah melihat semua, wali murid hanya miliknya.
     *
     * Filter `filter[status]`, `filter[tahun_ajaran_id]`, `filter[tingkat_tujuan]`. `search` mencari kode dan nama
     * anak. Urutan `sort` = `created_at` | `nama` (bawaan `-created_at`). Tanpa `filter[status]`, daftar Kepala
     * Sekolah tidak memuat pendaftar yang mengundurkan diri; lihat dengan `filter[status]=mengundurkan_diri`.
     */
    public function index(DaftarPendaftaranRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $kata = $request->kataCari();
        $query = Pendaftaran::query()->visibleTo($user)->with(['tahunAjaran', 'murid'])
            ->when($kata !== null, fn (Builder $query) => $query->where(fn (Builder $cari) => $cari
                ->where('kode', 'like', "%{$kata}%")
                ->orWhere('nama_lengkap', 'like', "%{$kata}%")))
            ->when($user->role === Role::SuperAdmin && ! $request->has('filter.status'), fn (Builder $query) => $query
                ->where('status', '!=', StatusPendaftaran::MengundurkanDiri));

        $pendaftaran = QueryBuilder::for($query, $request)
            ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('tahun_ajaran_id'), AllowedFilter::exact('tingkat_tujuan'))
            ->allowedSorts(AllowedSort::field('created_at'), AllowedSort::field('nama', 'nama_lengkap'))
            ->defaultSort('-created_at', '-id')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(PendaftaranResource::collection($pendaftaran));
    }

    /**
     * Detail pendaftaran beserta data wali pendaftar.
     */
    public function show(int $id): JsonResponse
    {
        $pendaftaran = Pendaftaran::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($pendaftaran);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)));
    }

    /**
     * Wali yang sudah login mendaftarkan kakak/adik untuk tahun ajaran PPDB di pengaturan. Tanpa unggahan; akta
     * kelahiran dan kartu keluarga dibawa ke sekolah. Formulir wajib lengkap dan `pernyataan` wajib dicentang. Ditolak
     * kalau PPDB ditutup, kuota penuh, atau NIK anak sudah punya pendaftaran yang sedang diproses atau diterima atau
     * sudah menjadi murid. Anak yang diterima ditautkan ke akun ini dengan `hubungan`.
     */
    public function store(BuatPendaftaranRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->daftar($user->profilWaliMurid(), $request->dataPendaftaran());

        return ApiResponse::success(
            new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)),
            "Pendaftaran {$pendaftaran->nama_panggilan} terkirim dengan kode {$pendaftaran->kode}. Pantau statusnya di menu PPDB.",
            status: 201,
        );
    }

    /**
     * Pendaftaran PPDB tanpa login, dengan isian dan aturan yang sama seperti `POST /pendaftaran` tanpa `hubungan`.
     * Dibatasi 3 pendaftaran per jam per IP. Simpan `kode` untuk mengecek status lewat `GET /public/pendaftaran/status`.
     * Kalau diterima, sekolah membuatkan akun wali dengan username NIS anak untuk kontak utama formulir.
     */
    public function storePublik(BuatPendaftaranRequest $request): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->daftar(null, $request->dataPendaftaran());

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
     * Menandai data formulir pendaftar sudah dicek sekolah. Centang Kartu Keluarga dan akta kelahiran terpisah, lewat
     * `PATCH /pendaftaran/{id}/berkas`.
     */
    public function verifikasi(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->verifikasi(Pendaftaran::query()->findOrFail($id), $user);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)), "Pendaftaran {$pendaftaran->kode} diverifikasi.");
    }

    /**
     * Menerima pendaftar yang sudah diverifikasi: membuat data murid (isian formulir, orang tua, dan centang berkas
     * ikut disalin), menautkannya ke wali pendaftar, dan menempatkannya di kelas kalau `kelas_id` dikirim. Berkas yang
     * belum lengkap tidak menahan penerimaan.
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

    /**
     * Mencatat pendaftar yang tidak jadi masuk; datanya tetap disimpan dan tidak dihitung kuota. Pendaftar yang sudah
     * diterima hanya bisa mundur kalau muridnya belum punya tagihan, rapor, atau absensi: murid itu dihapus, NIS, NISN,
     * dan NIK-nya dikosongkan, dan akun wali otomatis yang belum dipakai dinonaktifkan. Tanpa notifikasi ke wali.
     */
    public function mengundurkanDiri(MundurPendaftaranRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->mengundurkanDiri(Pendaftaran::query()->findOrFail($id), $request->alasan(), $user);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)), "{$pendaftaran->nama_lengkap} dicatat mengundurkan diri.");
    }

    /**
     * Mengembalikan pendaftar yang mundur sebelum diterima ke status sebelumnya (`status_sebelum_mundur`). Ditolak
     * kalau pendaftar mundur setelah diterima, kuota sudah penuh, atau NIK anak sudah dipakai pendaftaran lain.
     */
    public function batalkanMundur(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = $this->pendaftaranService->batalkanMundur(Pendaftaran::query()->findOrFail($id), $user);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)), "Pendaftaran {$pendaftaran->kode} kembali berstatus {$pendaftaran->status->label()}.");
    }

    /**
     * Mencentang atau menghapus centang Kartu Keluarga dan akta kelahiran yang sudah diserahkan ke sekolah, untuk
     * pendaftaran yang diajukan atau diverifikasi.
     */
    public function berkas(BerkasFisikRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pendaftaran = Pendaftaran::query()->findOrFail($id);
        $this->berkasFisik->centang($pendaftaran, $request->centang(), $user);

        return ApiResponse::success(new PendaftaranDetailResource($pendaftaran->load(self::RELASI_DETAIL)), 'Centang berkas tersimpan.');
    }
}
