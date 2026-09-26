<?php

namespace App\Policies;

use App\Models\Rapor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Rapor di luar jangkauan pengguna dibalas 404 (wali hanya melihat rapor terbit anaknya). Isi rapor hanya
 * diubah dan diajukan oleh guru pembuatnya; guru lain di kelas yang sama ditolak 403.
 */
class RaporPolicy
{
    public function view(User $user, Rapor $rapor): Response
    {
        return Rapor::query()->visibleTo($user)->whereKey($rapor->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function ubah(User $user, Rapor $rapor): Response
    {
        $lihat = $this->view($user, $rapor);

        if ($lihat->denied() || $user->guru?->id === $rapor->dibuat_oleh) {
            return $lihat;
        }

        return Response::deny();
    }
}
