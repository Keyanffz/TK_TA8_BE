<?php

namespace App\Http\Controllers\Api\V1\Publik;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agenda\DaftarAgendaRequest;
use App\Http\Requests\HalamanRequest;
use App\Http\Resources\AgendaResource;
use App\Http\Resources\GaleriAlbumResource;
use App\Http\Resources\GuruPublikResource;
use App\Http\Resources\PengumumanPublikResource;
use App\Models\Agenda;
use App\Models\GaleriAlbum;
use App\Models\Guru;
use App\Models\Pengumuman;
use App\Services\PengaturanService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Data landing page tanpa login. Semua isinya berasal dari CMS dan data yang ditandai publik.
 */
class PublikController extends Controller
{
    /**
     * Semua pengaturan grup `profil` dan `landing` sebagai objek datar berkunci lengkap, dengan pasangan
     * `*_url` untuk field gambar.
     */
    public function profil(PengaturanService $pengaturan): JsonResponse
    {
        return ApiResponse::success($pengaturan->untukRespons(['profil', 'landing']));
    }

    /**
     * Pengumuman publik yang sudah terbit, yang disematkan lebih dulu lalu terbaru.
     */
    public function pengumuman(HalamanRequest $request): JsonResponse
    {
        $pengumuman = Pengumuman::query()->where('is_publik', true)->terbit()
            ->orderByDesc('is_pinned')->orderByDesc('published_at')->orderByDesc('id')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(PengumumanPublikResource::collection($pengumuman));
    }

    public function detailPengumuman(string $slug): JsonResponse
    {
        $pengumuman = Pengumuman::query()->where('is_publik', true)->terbit()->where('slug', $slug)->firstOrFail();

        return ApiResponse::success(new PengumumanPublikResource($pengumuman));
    }

    /**
     * Agenda publik yang berlangsung di bulan `bulan` (YYYY-MM, bawaan bulan ini). Tidak berpaginasi.
     */
    public function agenda(DaftarAgendaRequest $request): JsonResponse
    {
        $agenda = Agenda::query()->where('is_publik', true)->berlangsungDi($request->bulan())
            ->orderBy('tanggal_mulai')->orderBy('id')->get();

        return ApiResponse::success(AgendaResource::collection($agenda));
    }

    /**
     * Album galeri publik, terbaru lebih dulu.
     */
    public function galeri(HalamanRequest $request): JsonResponse
    {
        $album = GaleriAlbum::query()->where('is_publik', true)->with('fotoPertama')->withCount('foto')
            ->orderByDesc('tanggal')->orderByDesc('id')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(GaleriAlbumResource::collection($album));
    }

    public function detailGaleri(string $slug): JsonResponse
    {
        $album = GaleriAlbum::query()->where('is_publik', true)->where('slug', $slug)
            ->with(['foto', 'fotoPertama'])->withCount('foto')->firstOrFail();

        return ApiResponse::success(new GaleriAlbumResource($album));
    }

    /**
     * Guru aktif yang ditandai tampil di landing page (termasuk Kepala Sekolah), Kepala Sekolah lebih dulu.
     */
    public function guru(): JsonResponse
    {
        $guru = Guru::query()->with('user')->where('tampil_di_landing', true)
            ->whereHas('user', fn (Builder $user) => $user->where('status', StatusAkun::Aktif))
            ->join('users', 'users.id', '=', 'guru.user_id')
            ->orderByRaw('CASE WHEN users.role = ? THEN 0 ELSE 1 END', [Role::SuperAdmin->value])
            ->orderBy('users.name')
            ->select('guru.*')
            ->get();

        return ApiResponse::success(GuruPublikResource::collection($guru));
    }
}
