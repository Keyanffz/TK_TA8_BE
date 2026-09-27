<?php

namespace App\Http\Controllers\Api\V1\WaliMurid;

use App\Http\Controllers\Controller;
use App\Http\Requests\UbahStatusAkunRequest;
use App\Http\Requests\WaliMurid\DaftarWaliMuridRequest;
use App\Http\Requests\WaliMurid\PerbaruiWaliMuridRequest;
use App\Http\Resources\WaliMuridDetailResource;
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
     * `search` mencari nama, username (NIS anak), email, dan nomor HP. Urutan `sort` = `nama` | `created_at`.
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

        return ApiResponse::success(new WaliMuridDetailResource($waliMurid));
    }

    /**
     * Mengubah data wali murid (nama, nomor HP, NIK, alamat, pekerjaan). Boleh sebagian; username (NIS anak) tidak
     * bisa diubah.
     */
    public function update(PerbaruiWaliMuridRequest $request, int $id, WaliMuridService $service, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $waliMurid = $service->perbarui(WaliMurid::query()->with('user')->findOrFail($id), $request->dataWali(), $kepalaSekolah);
        $waliMurid->load('murid.kelasAktif')->loadCount('murid');

        return ApiResponse::success(new WaliMuridDetailResource($waliMurid), "Data {$waliMurid->user->name} tersimpan.");
    }

    /**
     * Mengembalikan password wali murid ke tanggal lahir anak (DDMMYYYY) yang dia jadi kontak utamanya. Wali wajib
     * mengganti password saat login berikutnya dan semua sesi loginnya dicabut. Ditolak 422 `BUSINESS_RULE` kalau
     * wali bukan kontak utama anak mana pun.
     */
    public function resetPassword(int $id, WaliMuridService $service, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $waliMurid = WaliMurid::query()->with('user')->withCount('murid')->findOrFail($id);
        $anak = $service->resetPassword($waliMurid, $kepalaSekolah);

        return ApiResponse::success(
            new WaliMuridResource($waliMurid),
            "Password {$waliMurid->user->name} dikembalikan ke tanggal lahir {$anak->nama_panggilan} (DDMMYYYY) dan wajib diganti saat login berikutnya.",
        );
    }

    /**
     * Mengaktifkan atau menonaktifkan akun wali murid. Menonaktifkan mencabut semua sesi loginnya.
     */
    public function ubahStatus(UbahStatusAkunRequest $request, int $id, WaliMuridService $service, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $waliMurid = $service->ubahStatus(WaliMurid::query()->with('user')->withCount('murid')->findOrFail($id), $request->status(), $kepalaSekolah);

        return ApiResponse::success(
            new WaliMuridResource($waliMurid),
            "Akun {$waliMurid->user->name} sekarang berstatus {$waliMurid->user->status->label()}.",
        );
    }
}
