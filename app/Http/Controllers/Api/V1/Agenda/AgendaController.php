<?php

namespace App\Http\Controllers\Api\V1\Agenda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agenda\DaftarAgendaRequest;
use App\Http\Requests\Agenda\SimpanAgendaRequest;
use App\Http\Resources\AgendaResource;
use App\Models\Agenda;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class AgendaController extends Controller
{
    /**
     * Agenda sekolah yang berlangsung di bulan `bulan` (YYYY-MM, bawaan bulan ini), termasuk agenda yang mulai
     * di bulan sebelumnya atau berakhir di bulan berikutnya. Tidak berpaginasi, urut tanggal mulai.
     */
    public function index(DaftarAgendaRequest $request): JsonResponse
    {
        $agenda = Agenda::query()->berlangsungDi($request->bulan())->orderBy('tanggal_mulai')->orderBy('id')->get();

        return ApiResponse::success(AgendaResource::collection($agenda));
    }

    /**
     * Menambah agenda. `is_publik` = tampil di landing page.
     */
    public function store(SimpanAgendaRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $agenda = Agenda::query()->create([...$request->validated(), 'is_publik' => $request->boolean('is_publik'), 'dibuat_oleh' => $user->id]);

        return ApiResponse::success(new AgendaResource($agenda), "Agenda {$agenda->judul} ditambahkan.", status: 201);
    }

    public function update(SimpanAgendaRequest $request, int $id): JsonResponse
    {
        $agenda = Agenda::query()->findOrFail($id);
        $agenda->update($request->validated());

        return ApiResponse::success(new AgendaResource($agenda), 'Agenda tersimpan.');
    }

    public function destroy(int $id): JsonResponse
    {
        $agenda = Agenda::query()->findOrFail($id);
        $agenda->delete();

        return ApiResponse::success(null, "Agenda {$agenda->judul} dihapus.");
    }
}
