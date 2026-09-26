<?php

namespace App\Http\Controllers\Api\V1\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tagihan\DaftarTagihanRequest;
use App\Http\Resources\TagihanResource;
use App\Models\Tagihan;
use App\Models\User;
use App\Services\PengaturanService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TagihanController extends Controller
{
    /**
     * Daftar tagihan. Petugas keuangan melihat semua tagihan, guru lain hanya tagihan murid kelasnya
     * (read-only), wali murid hanya tagihan anaknya.
     *
     * Filter `filter[status]`, `filter[periode]` (YYYY-MM), `filter[kelas_id]`, `filter[murid_id]`,
     * `filter[jenis_tagihan_id]`. `search` mencari kode tagihan dan nama murid.
     * Urutan `sort` = `jatuh_tempo` | `periode` | `created_at` (bawaan `-created_at`).
     */
    public function index(DaftarTagihanRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $kata = $request->kataCari();
        $query = Tagihan::query()->visibleTo($user)->with(['murid.kelasAktif', 'jenisTagihan'])
            ->when($kata !== null, fn (Builder $query) => $query->where(fn (Builder $cari) => $cari
                ->where('kode', 'like', "%{$kata}%")
                ->orWhereHas('murid', fn (Builder $murid) => $murid->cari($kata))));

        $tagihan = QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::callback('periode', fn (Builder $query, mixed $periode) => $query
                    ->whereDate('periode', $periode.'-01')),
                AllowedFilter::callback('kelas_id', fn (Builder $query, mixed $kelasId) => $query
                    ->whereHas('murid.kelas', fn (Builder $kelas) => $kelas->whereKey($kelasId))),
                AllowedFilter::exact('murid_id'),
                AllowedFilter::exact('jenis_tagihan_id'),
            )
            ->allowedSorts('jatuh_tempo', 'periode', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(TagihanResource::collection($tagihan));
    }

    /**
     * Detail tagihan beserta riwayat pembayaran dan rekening sekolah untuk transfer.
     */
    public function show(int $id, PengaturanService $pengaturan): JsonResponse
    {
        $tagihan = Tagihan::query()->findOrFail($id);
        Gate::authorize('view', $tagihan);

        $tagihan->load([
            'murid.kelasAktif', 'jenisTagihan',
            'pembayaran' => fn ($pembayaran) => $pembayaran->with(['pembayar', 'verifikator'])->latest('id'),
        ]);

        return ApiResponse::success(TagihanResource::make($tagihan)->denganRekening($pengaturan->rekeningSekolah()));
    }
}
