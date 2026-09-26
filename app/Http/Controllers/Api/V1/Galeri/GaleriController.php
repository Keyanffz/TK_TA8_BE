<?php

namespace App\Http\Controllers\Api\V1\Galeri;

use App\Http\Controllers\Controller;
use App\Http\Requests\Galeri\DaftarGaleriRequest;
use App\Http\Requests\Galeri\PerbaruiFotoGaleriRequest;
use App\Http\Requests\Galeri\SimpanAlbumRequest;
use App\Http\Requests\Galeri\TambahFotoGaleriRequest;
use App\Http\Resources\GaleriAlbumResource;
use App\Models\GaleriAlbum;
use App\Models\GaleriFoto;
use App\Services\GaleriService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class GaleriController extends Controller
{
    public function __construct(private readonly GaleriService $galeriService) {}

    /**
     * Semua album galeri (publik dan belum publik) beserta fotonya, terbaru lebih dulu. A7 tidak punya detail
     * album untuk Kepala Sekolah, jadi foto ikut di daftar ini. Filter `filter[is_publik]`, `search` mencari
     * judul, urutan `sort` = `tanggal` | `created_at`.
     */
    public function index(DaftarGaleriRequest $request): JsonResponse
    {
        $kata = $request->kataCari();
        $album = QueryBuilder::for(GaleriAlbum::query()->with(['foto', 'fotoPertama'])->withCount('foto')
            ->when($kata !== null, fn (Builder $query) => $query->where('judul', 'like', "%{$kata}%")), $request)
            ->allowedFilters(AllowedFilter::exact('is_publik'))
            ->allowedSorts('tanggal', 'created_at')
            ->defaultSort('-tanggal', '-id')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(GaleriAlbumResource::collection($album));
    }

    /**
     * Membuat album (`multipart/form-data` kalau menyertakan `cover`). Album baru tidak publik kecuali
     * `is_publik` dikirim.
     */
    public function store(SimpanAlbumRequest $request): JsonResponse
    {
        $album = $this->galeriService->buat($request->dataAlbum(), $request->cover());

        return ApiResponse::success(new GaleriAlbumResource($album->load('foto', 'fotoPertama')->loadCount('foto')), "Album {$album->judul} dibuat.", status: 201);
    }

    public function update(SimpanAlbumRequest $request, int $id): JsonResponse
    {
        $album = $this->galeriService->perbarui(GaleriAlbum::query()->findOrFail($id), $request->dataAlbum(), $request->cover());

        return ApiResponse::success(new GaleriAlbumResource($album->load('foto', 'fotoPertama')->loadCount('foto')), 'Album tersimpan.');
    }

    /**
     * Menghapus album beserta semua foto dan file-nya.
     */
    public function destroy(int $id): JsonResponse
    {
        $album = GaleriAlbum::query()->findOrFail($id);
        $this->galeriService->hapus($album);

        return ApiResponse::success(null, "Album {$album->judul} dihapus.");
    }

    /**
     * Menambah foto ke album (`foto[]` maksimal 20 per unggahan), diurutkan setelah foto yang sudah ada.
     */
    public function tambahFoto(TambahFotoGaleriRequest $request, int $id): JsonResponse
    {
        $album = $this->galeriService->tambahFoto(GaleriAlbum::query()->findOrFail($id), $request->foto());

        return ApiResponse::success(new GaleriAlbumResource($album->load('foto', 'fotoPertama')->loadCount('foto')), count($request->foto()).' foto ditambahkan.', status: 201);
    }

    /**
     * Mengubah keterangan dan urutan foto.
     */
    public function perbaruiFoto(PerbaruiFotoGaleriRequest $request, int $id): JsonResponse
    {
        $foto = GaleriFoto::query()->findOrFail($id);
        $foto->update($request->validated());

        return ApiResponse::success([
            'id' => $foto->id,
            'caption' => $foto->caption,
            'urutan' => $foto->urutan,
        ], 'Foto tersimpan.');
    }

    public function hapusFoto(int $id): JsonResponse
    {
        $this->galeriService->hapusFoto(GaleriFoto::query()->findOrFail($id));

        return ApiResponse::success(null, 'Foto dihapus.');
    }
}
