<?php

namespace App\Http\Controllers\Api\V1\Kelas;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kelas\DaftarKelasRequest;
use App\Http\Requests\Kelas\SimpanKelasRequest;
use App\Http\Resources\KelasDetailResource;
use App\Http\Resources\KelasResource;
use App\Models\Kelas;
use App\Models\User;
use App\Services\KelasService;
use App\Support\ApiResponse;
use App\Support\Jangkauan;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class KelasController extends Controller
{
    public function __construct(private readonly KelasService $kelasService) {}

    /**
     * Daftar kelas beserta jumlah murid aktif. Kepala Sekolah melihat semua kelas; guru hanya kelas yang
     * dia ampu (wali kelas atau pendamping) di tahun ajaran aktif.
     *
     * Filter `filter[tahun_ajaran_id]`. Urutan `sort` = `nama` | `created_at` (awali `-` untuk menurun).
     */
    public function index(DaftarKelasRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $query = Kelas::query()->with(['tahunAjaran', 'waliKelas.user', 'guruPendamping.user'])->withCount('muridAktif');

        if ($user->role === Role::Guru) {
            $query->diampuOleh($user);
        }

        $kelas = QueryBuilder::for($query, $request)
            ->allowedFilters(AllowedFilter::exact('tahun_ajaran_id'))
            ->allowedSorts('nama', 'created_at')
            ->defaultSort('nama')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(KelasResource::collection($kelas));
    }

    /**
     * Detail kelas beserta murid di dalamnya.
     */
    public function show(int $id): JsonResponse
    {
        $kelas = Kelas::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($kelas);

        return ApiResponse::success(new KelasDetailResource($kelas->muatDetail()));
    }

    public function store(SimpanKelasRequest $request): JsonResponse
    {
        $kelas = $this->kelasService->buat($request->validated());

        return ApiResponse::success(new KelasDetailResource($kelas->muatDetail()), "Kelas {$kelas->nama} dibuat.", status: 201);
    }

    /**
     * Mengubah data kelas. Kapasitas tidak bisa di bawah jumlah murid aktif, dan tahun ajaran tidak bisa
     * diganti setelah kelas berisi murid.
     */
    public function update(SimpanKelasRequest $request, int $id): JsonResponse
    {
        $kelas = $this->kelasService->perbarui(Kelas::query()->findOrFail($id), $request->validated());

        return ApiResponse::success(new KelasDetailResource($kelas->muatDetail()), 'Data kelas tersimpan.');
    }

    /**
     * Menghapus kelas yang belum punya murid, kegiatan, atau rapor.
     */
    public function destroy(int $id): JsonResponse
    {
        $kelas = Kelas::query()->findOrFail($id);
        $this->kelasService->hapus($kelas);

        return ApiResponse::success(null, "Kelas {$kelas->nama} dihapus.");
    }
}
