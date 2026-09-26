<?php

namespace App\Http\Controllers\Api\V1\Pengumuman;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pengumuman\DaftarPengumumanRequest;
use App\Http\Requests\Pengumuman\SimpanPengumumanRequest;
use App\Http\Resources\PengumumanResource;
use App\Models\Pengumuman;
use App\Models\User;
use App\Services\PengumumanService;
use App\Support\ApiResponse;
use App\Support\Jangkauan;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PengumumanController extends Controller
{
    private const RELASI = ['penulis', 'kelas', 'murid'];

    public function __construct(private readonly PengumumanService $pengumumanService) {}

    /**
     * Feed pengumuman untuk pengguna: target semua, target sesuai role, kelas yang diampu / kelas anak, dan
     * murid di kelasnya / anaknya, ditambah tulisannya sendiri (termasuk draft). Kepala Sekolah melihat semua.
     * Yang disematkan lebih dulu, lalu terbaru.
     *
     * Filter `filter[target]`, `filter[terbit]` (`0` = draft). `search` mencari judul.
     */
    public function index(DaftarPengumumanRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $kata = $request->kataCari();
        $query = Pengumuman::query()->visibleTo($user)->with(self::RELASI)
            ->when($kata !== null, fn (Builder $query) => $query->where('judul', 'like', "%{$kata}%"))
            ->orderByDesc('is_pinned')
            ->orderByDesc(DB::raw('COALESCE(published_at, created_at)'))
            ->orderByDesc('id');

        $pengumuman = QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::exact('target'),
                AllowedFilter::callback('terbit', fn (Builder $query, mixed $terbit) => filter_var($terbit, FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNotNull('published_at')
                    : $query->whereNull('published_at')),
            )
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(PengumumanResource::collection($pengumuman));
    }

    public function show(int $id): JsonResponse
    {
        $pengumuman = Pengumuman::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($pengumuman);

        return ApiResponse::success(new PengumumanResource($pengumuman->load(self::RELASI)));
    }

    /**
     * Membuat pengumuman. `publish: true` langsung terbit dan penerima diberi notifikasi lewat antrean;
     * `publish: false` disimpan sebagai draft.
     */
    public function store(SimpanPengumumanRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $pengumuman = $this->pengumumanService->buat(
            $request->dataPengumuman(), $request->kelasIds(), $request->muridIds(), $request->boolean('publish'), $user,
        );

        return ApiResponse::success(
            new PengumumanResource($pengumuman->load(self::RELASI)),
            $pengumuman->published_at === null ? 'Pengumuman disimpan sebagai draft.' : 'Pengumuman diterbitkan.',
            status: 201,
        );
    }

    /**
     * Mengubah pengumuman (penulis atau Kepala Sekolah). Draft yang diterbitkan memicu notifikasi; pengumuman
     * yang sudah terbit tidak dikirimi notifikasi ulang.
     */
    public function update(SimpanPengumumanRequest $request, int $id): JsonResponse
    {
        $pengumuman = Pengumuman::query()->findOrFail($id);
        Gate::authorize('kelola', $pengumuman);

        $pengumuman = $this->pengumumanService->perbarui(
            $pengumuman, $request->dataPengumuman(), $request->kelasIds(), $request->muridIds(), $request->boolean('publish'),
        );

        return ApiResponse::success(new PengumumanResource($pengumuman->load(self::RELASI)), 'Pengumuman tersimpan.');
    }

    public function destroy(int $id): JsonResponse
    {
        $pengumuman = Pengumuman::query()->findOrFail($id);
        Gate::authorize('kelola', $pengumuman);
        $pengumuman->delete();

        return ApiResponse::success(null, 'Pengumuman dihapus.');
    }
}
