<?php

namespace App\Http\Controllers\Api\V1\WaliMurid;

use App\Http\Controllers\Controller;
use App\Http\Requests\UbahStatusAkunRequest;
use App\Http\Requests\WaliMurid\DaftarWaliMuridRequest;
use App\Http\Resources\WaliMuridResource;
use App\Models\User;
use App\Models\WaliMurid;
use App\Services\WaliMuridService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class WaliMuridController extends Controller
{
    /**
     * Daftar wali murid beserta jumlah anak yang tertaut.
     *
     * `search` mencari nama, email, dan nomor HP. Urutan `sort` = `nama` | `created_at`.
     */
    public function index(DaftarWaliMuridRequest $request): JsonResponse
    {
        $urutNama = AllowedSort::callback('nama', fn (Builder $query, bool $menurun) => $query->orderBy(
            User::query()->select('name')->whereColumn('users.id', 'wali_murid.user_id'),
            $menurun ? 'desc' : 'asc',
        ));

        $wali = QueryBuilder::for(WaliMurid::query()->with('user')->withCount('murid')->cari($request->kataCari()), $request)
            ->allowedSorts($urutNama, AllowedSort::field('created_at'))
            ->defaultSort($urutNama)
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(WaliMuridResource::collection($wali));
    }

    /**
     * Detail wali murid beserta anak yang tertaut dan kelas aktifnya.
     */
    public function show(int $id): JsonResponse
    {
        $waliMurid = WaliMurid::query()->with(['user', 'murid.kelasAktif'])->withCount('murid')->findOrFail($id);

        return ApiResponse::success(new WaliMuridResource($waliMurid));
    }

    /**
     * Mengaktifkan atau menonaktifkan akun wali murid. Menonaktifkan mencabut semua sesi loginnya.
     */
    public function ubahStatus(UbahStatusAkunRequest $request, int $id, WaliMuridService $service, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $waliMurid = $service->ubahStatus(WaliMurid::query()->with('user')->findOrFail($id), $request->status(), $kepalaSekolah);

        return ApiResponse::success(
            new WaliMuridResource($waliMurid),
            "Akun {$waliMurid->user->name} sekarang berstatus {$waliMurid->user->status->label()}.",
        );
    }
}
