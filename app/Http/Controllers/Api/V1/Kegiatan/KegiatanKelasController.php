<?php

namespace App\Http\Controllers\Api\V1\Kegiatan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kegiatan\DaftarKegiatanRequest;
use App\Http\Requests\Kegiatan\SimpanKegiatanRequest;
use App\Http\Requests\Kegiatan\TambahFotoKegiatanRequest;
use App\Http\Resources\KegiatanKelasResource;
use App\Models\KegiatanFoto;
use App\Models\KegiatanKelas;
use App\Models\User;
use App\Services\KegiatanKelasService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class KegiatanKelasController extends Controller
{
    private const RELASI = ['kelas', 'guru.user', 'foto'];

    public function __construct(private readonly KegiatanKelasService $kegiatanService) {}

    /**
     * Kegiatan kelas beserta fotonya, terbaru lebih dulu. Kepala Sekolah melihat semua kelas, guru kelas yang
     * dia ampu di tahun ajaran aktif, wali murid kelas yang pernah atau sedang diikuti anaknya.
     *
     * Filter `filter[kelas_id]`. `search` mencari judul dan tema. Urutan `sort` = `tanggal` | `created_at`.
     */
    public function index(DaftarKegiatanRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $kata = $request->kataCari();
        $query = KegiatanKelas::query()->visibleTo($user)->with(self::RELASI)
            ->when($kata !== null, fn (Builder $query) => $query->where(fn (Builder $cari) => $cari
                ->where('judul', 'like', "%{$kata}%")
                ->orWhere('tema', 'like', "%{$kata}%")));

        $kegiatan = QueryBuilder::for($query, $request)
            ->allowedFilters(AllowedFilter::exact('kelas_id'))
            ->allowedSorts('tanggal', 'created_at')
            ->defaultSort('-tanggal', '-id')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(KegiatanKelasResource::collection($kegiatan));
    }

    public function show(int $id): JsonResponse
    {
        $kegiatan = KegiatanKelas::query()->findOrFail($id);
        Gate::authorize('view', $kegiatan);

        return ApiResponse::success(new KegiatanKelasResource($kegiatan->load(self::RELASI)));
    }

    /**
     * Mencatat kegiatan kelas (`multipart/form-data`, `foto[]` maksimal 10). Guru hanya untuk kelas yang dia
     * ampu; Kepala Sekolah untuk kelas mana pun.
     */
    public function store(SimpanKegiatanRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $kegiatan = $this->kegiatanService->buat($request->dataKegiatan(), $request->foto(), $user);

        return ApiResponse::success(new KegiatanKelasResource($kegiatan->load(self::RELASI)), "Kegiatan {$kegiatan->judul} tersimpan.", status: 201);
    }

    /**
     * Mengubah tanggal, tema, judul, dan deskripsi. Hanya guru pembuat dan Kepala Sekolah.
     */
    public function update(SimpanKegiatanRequest $request, int $id): JsonResponse
    {
        $kegiatan = KegiatanKelas::query()->findOrFail($id);
        Gate::authorize('kelola', $kegiatan);
        $kegiatan->update($request->dataKegiatan());

        return ApiResponse::success(new KegiatanKelasResource($kegiatan->load(self::RELASI)), 'Kegiatan tersimpan.');
    }

    /**
     * Menghapus kegiatan beserta semua fotonya. Hanya guru pembuat dan Kepala Sekolah.
     */
    public function destroy(int $id): JsonResponse
    {
        $kegiatan = KegiatanKelas::query()->findOrFail($id);
        Gate::authorize('kelola', $kegiatan);
        $this->kegiatanService->hapus($kegiatan);

        return ApiResponse::success(null, "Kegiatan {$kegiatan->judul} dihapus.");
    }

    /**
     * Menambah foto ke kegiatan (`foto[]` maksimal 10 per unggahan, 30 per kegiatan).
     */
    public function tambahFoto(TambahFotoKegiatanRequest $request, int $id): JsonResponse
    {
        $kegiatan = KegiatanKelas::query()->findOrFail($id);
        Gate::authorize('kelola', $kegiatan);
        $this->kegiatanService->tambahFoto($kegiatan, $request->foto());

        return ApiResponse::success(new KegiatanKelasResource($kegiatan->load(self::RELASI)), count($request->foto()).' foto ditambahkan.', status: 201);
    }

    /**
     * Menghapus satu foto kegiatan. Hanya guru pembuat kegiatan dan Kepala Sekolah.
     */
    public function hapusFoto(int $id): JsonResponse
    {
        $foto = KegiatanFoto::query()->with('kegiatan')->findOrFail($id);
        Gate::authorize('kelola', $foto->kegiatan);
        $this->kegiatanService->hapusFoto($foto);

        return ApiResponse::success(null, 'Foto dihapus.');
    }
}
