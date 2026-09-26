<?php

namespace App\Http\Controllers\Api\V1\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\JenisTagihan\DaftarJenisTagihanRequest;
use App\Http\Requests\JenisTagihan\SimpanJenisTagihanRequest;
use App\Http\Resources\JenisTagihanResource;
use App\Models\JenisTagihan;
use App\Services\JenisTagihanService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class JenisTagihanController extends Controller
{
    public function __construct(private readonly JenisTagihanService $jenisTagihanService) {}

    /**
     * Daftar jenis tagihan. Filter `filter[tahun_ajaran_id]`, `filter[periode]`, `filter[is_aktif]`.
     * Urutan `sort` = `nama` | `created_at`.
     */
    public function index(DaftarJenisTagihanRequest $request): JsonResponse
    {
        $jenisTagihan = QueryBuilder::for(JenisTagihan::query()->with('tahunAjaran'), $request)
            ->allowedFilters(AllowedFilter::exact('tahun_ajaran_id'), AllowedFilter::exact('periode'), AllowedFilter::exact('is_aktif'))
            ->allowedSorts('nama', 'created_at')
            ->defaultSort('nama')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(JenisTagihanResource::collection($jenisTagihan));
    }

    public function store(SimpanJenisTagihanRequest $request): JsonResponse
    {
        $jenisTagihan = $this->jenisTagihanService->buat($request->validated());

        return ApiResponse::success(new JenisTagihanResource($jenisTagihan->load('tahunAjaran')), "Jenis tagihan {$jenisTagihan->nama} dibuat.", status: 201);
    }

    /**
     * Mengubah jenis tagihan. Nominal baru hanya berlaku untuk tagihan berikutnya.
     */
    public function update(SimpanJenisTagihanRequest $request, int $id): JsonResponse
    {
        $jenisTagihan = $this->jenisTagihanService->perbarui(JenisTagihan::query()->findOrFail($id), $request->validated());

        return ApiResponse::success(new JenisTagihanResource($jenisTagihan->load('tahunAjaran')), 'Jenis tagihan tersimpan.');
    }

    /**
     * Menghapus jenis tagihan yang belum dipakai di tagihan atau keringanan.
     */
    public function destroy(int $id): JsonResponse
    {
        $jenisTagihan = JenisTagihan::query()->findOrFail($id);
        $this->jenisTagihanService->hapus($jenisTagihan);

        return ApiResponse::success(null, "Jenis tagihan {$jenisTagihan->nama} dihapus.");
    }
}
