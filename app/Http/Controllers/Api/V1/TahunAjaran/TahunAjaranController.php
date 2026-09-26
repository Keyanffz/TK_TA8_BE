<?php

namespace App\Http\Controllers\Api\V1\TahunAjaran;

use App\Http\Controllers\Controller;
use App\Http\Requests\TahunAjaran\DaftarTahunAjaranRequest;
use App\Http\Requests\TahunAjaran\SimpanTahunAjaranRequest;
use App\Http\Resources\TahunAjaranResource;
use App\Models\TahunAjaran;
use App\Services\TahunAjaranService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\QueryBuilder;

class TahunAjaranController extends Controller
{
    public function __construct(private readonly TahunAjaranService $tahunAjaranService) {}

    /**
     * Daftar tahun ajaran, terbaru lebih dulu. Urutan `sort` = `nama` | `tanggal_mulai` (awali `-` untuk menurun).
     */
    public function index(DaftarTahunAjaranRequest $request): JsonResponse
    {
        $tahunAjaran = QueryBuilder::for(TahunAjaran::class, $request)
            ->allowedSorts('nama', 'tanggal_mulai')
            ->defaultSort('-tanggal_mulai')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(TahunAjaranResource::collection($tahunAjaran));
    }

    /**
     * Membuat tahun ajaran. Tahun ajaran pertama langsung aktif; berikutnya dibuat tidak aktif.
     */
    public function store(SimpanTahunAjaranRequest $request): JsonResponse
    {
        $tahunAjaran = $this->tahunAjaranService->buat($request->validated());

        return ApiResponse::success(new TahunAjaranResource($tahunAjaran), "Tahun ajaran {$tahunAjaran->nama} dibuat.", status: 201);
    }

    public function update(SimpanTahunAjaranRequest $request, int $id): JsonResponse
    {
        $tahunAjaran = $this->tahunAjaranService->perbarui(TahunAjaran::query()->findOrFail($id), $request->validated());

        return ApiResponse::success(new TahunAjaranResource($tahunAjaran), 'Tahun ajaran tersimpan.');
    }

    /**
     * Menghapus tahun ajaran yang belum dipakai (belum punya kelas, tagihan, jenis tagihan, atau pendaftar PPDB).
     */
    public function destroy(int $id): JsonResponse
    {
        $tahunAjaran = TahunAjaran::query()->findOrFail($id);
        $this->tahunAjaranService->hapus($tahunAjaran);

        return ApiResponse::success(null, "Tahun ajaran {$tahunAjaran->nama} dihapus.");
    }

    /**
     * Menjadikan tahun ajaran ini satu-satunya yang aktif.
     */
    public function aktifkan(int $id): JsonResponse
    {
        $tahunAjaran = $this->tahunAjaranService->aktifkan(TahunAjaran::query()->findOrFail($id));

        return ApiResponse::success(new TahunAjaranResource($tahunAjaran), "Tahun ajaran {$tahunAjaran->nama} sekarang aktif.");
    }
}
