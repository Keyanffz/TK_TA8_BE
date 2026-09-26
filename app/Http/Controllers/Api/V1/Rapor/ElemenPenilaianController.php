<?php

namespace App\Http\Controllers\Api\V1\Rapor;

use App\Http\Controllers\Controller;
use App\Http\Requests\ElemenPenilaian\SimpanElemenPenilaianRequest;
use App\Http\Resources\ElemenPenilaianResource;
use App\Models\ElemenPenilaian;
use App\Services\ElemenPenilaianService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ElemenPenilaianController extends Controller
{
    public function __construct(private readonly ElemenPenilaianService $elemenService) {}

    /**
     * Semua elemen penilaian rapor (aktif dan nonaktif) urut `urutan`. Tidak berpaginasi.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(ElemenPenilaianResource::collection(ElemenPenilaian::query()->orderBy('urutan')->orderBy('id')->get()));
    }

    /**
     * Menambah elemen. Elemen aktif otomatis ikut di rapor yang dibuat sesudahnya.
     */
    public function store(SimpanElemenPenilaianRequest $request): JsonResponse
    {
        $elemen = $this->elemenService->buat($request->validated());

        return ApiResponse::success(new ElemenPenilaianResource($elemen), "Elemen {$elemen->nama} ditambahkan.", status: 201);
    }

    /**
     * Mengubah elemen. Menonaktifkan elemen membuatnya tidak ikut di rapor baru; rapor lama tidak berubah.
     */
    public function update(SimpanElemenPenilaianRequest $request, int $id): JsonResponse
    {
        $elemen = ElemenPenilaian::query()->findOrFail($id);
        $elemen->update($request->validated());

        return ApiResponse::success(new ElemenPenilaianResource($elemen), 'Elemen penilaian tersimpan.');
    }

    /**
     * Menghapus elemen yang belum pernah dipakai di rapor.
     */
    public function destroy(int $id): JsonResponse
    {
        $elemen = ElemenPenilaian::query()->findOrFail($id);
        $this->elemenService->hapus($elemen);

        return ApiResponse::success(null, "Elemen {$elemen->nama} dihapus.");
    }
}
