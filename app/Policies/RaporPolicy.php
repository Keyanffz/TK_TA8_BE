<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Rapor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Rapor di luar jangkauan pengguna dibalas 404 (wali hanya melihat rapor terbit anaknya). Isi rapor diubah
 * dan diajukan oleh guru pembuatnya (Kepala Sekolah juga boleh memperbaiki isinya saat diajukan); guru lain
 * di kelas yang sama ditolak 403.
 */
class RaporPolicy
{
    public function view(User $user, Rapor $rapor): Response
    {
        return Rapor::query()->visibleTo($user)->whereKey($rapor->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * `PUT /rapor/{id}`: guru pembuat, atau Kepala Sekolah yang memperbaiki rapor sebelum terbit. Status yang
     * boleh diisi masing-masing dicek `RaporService::isi()`.
     */
    public function isi(User $user, Rapor $rapor): Response
    {
        $lihat = $this->view($user, $rapor);

        return $lihat->allowed() && $user->role === Role::SuperAdmin ? $lihat : $this->ubah($user, $rapor);
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
