<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardRequest;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Isi beranda sesuai role: Kepala Sekolah (statistik, keuangan bulan ini, grafik pemasukan 12 bulan, tindakan
     * tertunda), guru (kelas yang diampu, progres rapor, keuangan kelas), atau wali murid (satu anak, bisa dipilih
     * dengan `murid_id`; anak orang lain dibalas 404).
     *
     * @throws ModelNotFoundException
     */
    public function __invoke(DashboardRequest $request, #[CurrentUser] User $user, DashboardService $dashboard): JsonResponse
    {
        return ApiResponse::success(match ($user->role) {
            Role::SuperAdmin => $dashboard->kepalaSekolah($user),
            Role::Guru => $dashboard->guru($user),
            Role::WaliMurid => $dashboard->waliMurid($user, $request->muridId()),
        });
    }
}
