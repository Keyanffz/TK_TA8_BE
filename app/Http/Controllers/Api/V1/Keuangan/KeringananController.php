<?php

namespace App\Http\Controllers\Api\V1\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keringanan\DaftarKeringananRequest;
use App\Http\Requests\Keringanan\SimpanKeringananRequest;
use App\Http\Resources\KeringananResource;
use App\Models\Keringanan;
use App\Models\User;
use App\Services\KeringananService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class KeringananController extends Controller
{
    private const RELASI = ['murid', 'jenisTagihan', 'pembuat'];

    public function __construct(private readonly KeringananService $keringananService) {}

    /**
     * Daftar keringanan. Filter `filter[murid_id]`, `filter[jenis_tagihan_id]`; `search` mencari nama murid.
     * Urutan `sort` = `berlaku_mulai` | `created_at` (bawaan `-created_at`).
     */
    public function index(DaftarKeringananRequest $request): JsonResponse
    {
        $kata = $request->kataCari();
        $query = Keringanan::query()->with(self::RELASI)
            ->when($kata !== null, fn (Builder $query) => $query->whereHas('murid', fn (Builder $murid) => $murid->cari($kata)));

        $keringanan = QueryBuilder::for($query, $request)
            ->allowedFilters(AllowedFilter::exact('murid_id'), AllowedFilter::exact('jenis_tagihan_id'))
            ->allowedSorts('berlaku_mulai', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(KeringananResource::collection($keringanan));
    }

    /**
     * Menambah keringanan. Berlaku untuk tagihan yang dibuat sesudahnya; tagihan yang sudah ada tidak berubah.
     */
    public function store(SimpanKeringananRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $keringanan = $this->keringananService->buat($request->validated(), $user);

        return ApiResponse::success(new KeringananResource($keringanan->load(self::RELASI)), 'Keringanan tersimpan.', status: 201);
    }

    public function update(SimpanKeringananRequest $request, int $id): JsonResponse
    {
        $keringanan = $this->keringananService->perbarui(Keringanan::query()->findOrFail($id), $request->validated());

        return ApiResponse::success(new KeringananResource($keringanan->load(self::RELASI)), 'Keringanan tersimpan.');
    }

    public function destroy(int $id): JsonResponse
    {
        Keringanan::query()->findOrFail($id)->delete();

        return ApiResponse::success(null, 'Keringanan dihapus.');
    }
}
