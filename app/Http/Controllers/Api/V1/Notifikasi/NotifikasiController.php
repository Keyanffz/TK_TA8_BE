<?php

namespace App\Http\Controllers\Api\V1\Notifikasi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifikasi\DaftarNotifikasiRequest;
use App\Http\Resources\NotifikasiResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class NotifikasiController extends Controller
{
    /**
     * Notifikasi milik pengguna yang sedang masuk, terbaru lebih dulu. `filter[dibaca]=0` atau `false` hanya yang belum dibaca.
     */
    public function index(DaftarNotifikasiRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $notifikasi = $user->notifications()
            ->when($request->has('filter.dibaca'), fn ($query) => $request->boolean('filter.dibaca') ? $query->whereNotNull('read_at') : $query->whereNull('read_at'))
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(NotifikasiResource::collection($notifikasi));
    }

    /**
     * Jumlah notifikasi yang belum dibaca, untuk badge di dashboard.
     */
    public function belumDibaca(#[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::success(['jumlah' => $user->unreadNotifications()->count()]);
    }

    /**
     * Menandai satu notifikasi sudah dibaca. Notifikasi milik pengguna lain dibalas 404.
     */
    public function baca(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $notifikasi = $user->notifications()->findOrFail($id);
        $notifikasi->markAsRead();

        return ApiResponse::success(new NotifikasiResource($notifikasi));
    }

    /**
     * Menandai semua notifikasi pengguna sudah dibaca. `jumlah` = notifikasi yang baru ditandai.
     */
    public function bacaSemua(#[CurrentUser] User $user): JsonResponse
    {
        $jumlah = $user->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['jumlah' => $jumlah], "{$jumlah} notifikasi ditandai sudah dibaca.");
    }
}
