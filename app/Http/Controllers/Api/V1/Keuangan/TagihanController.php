<?php

namespace App\Http\Controllers\Api\V1\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\AlasanRequest;
use App\Http\Requests\Tagihan\BuatTagihanSekaliRequest;
use App\Http\Requests\Tagihan\DaftarTagihanRequest;
use App\Http\Requests\Tagihan\GenerateTagihanRequest;
use App\Http\Resources\TagihanResource;
use App\Models\JenisTagihan;
use App\Models\Tagihan;
use App\Models\User;
use App\Services\PengaturanService;
use App\Services\TagihanService;
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

    /**
     * Membuat tagihan sekali bayar (uang pangkal, seragam) untuk murid tertentu (`murid_ids`) atau semua murid
     * aktif satu kelas (`kelas_id`). Murid yang sudah punya tagihan jenis itu (selain dibatalkan), tidak aktif,
     * atau tingkat kelasnya tidak sesuai dilewati.
     */
    public function store(BuatTagihanSekaliRequest $request, TagihanService $tagihanService, #[CurrentUser] User $user): JsonResponse
    {
        $muridIds = $request->muridIds() ?? $tagihanService->muridDiKelas($request->integer('kelas_id'));
        $hasil = $tagihanService->buatSekali(
            JenisTagihan::query()->findOrFail($request->integer('jenis_tagihan_id')),
            $muridIds,
            $request->jatuhTempo(),
            $user,
        );

        return ApiResponse::success($hasil, "{$hasil['dibuat']} tagihan dibuat, {$hasil['dilewati']} murid dilewati.", status: 201);
    }

    /**
     * Membuat tagihan bulanan untuk satu periode secara manual. Aman diulang: tagihan yang sudah ada dilewati.
     */
    public function generate(GenerateTagihanRequest $request, TagihanService $tagihanService, #[CurrentUser] User $user): JsonResponse
    {
        $hasil = $tagihanService->generateBulanan($request->periode(), $user);

        return ApiResponse::success($hasil, "Tagihan {$request->periode()->translatedFormat('F Y')}: {$hasil['dibuat']} dibuat, {$hasil['dilewati']} sudah ada.");
    }

    /**
     * Membatalkan tagihan. Ditolak untuk tagihan yang sudah lunas atau masih punya bukti transfer yang
     * menunggu verifikasi. Alasan disimpan di `catatan`.
     */
    public function batalkan(AlasanRequest $request, int $id, TagihanService $tagihanService, #[CurrentUser] User $user): JsonResponse
    {
        $tagihan = $tagihanService->batalkan(Tagihan::query()->findOrFail($id), $request->alasan(), $user);
        $tagihan->load(['murid.kelasAktif', 'jenisTagihan']);

        return ApiResponse::success(new TagihanResource($tagihan), "Tagihan {$tagihan->kode} dibatalkan.");
    }
}
