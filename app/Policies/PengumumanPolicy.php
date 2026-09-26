<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Pengumuman;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Pengumuman di luar feed pengguna dibalas 404. Mengubah dan menghapus hanya untuk penulisnya dan Kepala Sekolah.
 */
class PengumumanPolicy
{
    public function view(User $user, Pengumuman $pengumuman): Response
    {
        return Pengumuman::query()->visibleTo($user)->whereKey($pengumuman->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function kelola(User $user, Pengumuman $pengumuman): Response
    {
        $lihat = $this->view($user, $pengumuman);

        if ($lihat->denied() || $user->role === Role::SuperAdmin || $user->id === $pengumuman->penulis_id) {
            return $lihat;
        }

        return Response::deny();
    }
}
