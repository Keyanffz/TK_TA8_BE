<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\KegiatanKelas;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Kegiatan di luar jangkauan pengguna dibalas 404. Yang boleh mengubah, menghapus, dan mengelola foto hanya
 * guru pembuatnya dan Kepala Sekolah; guru lain di kelas yang sama ditolak 403.
 */
class KegiatanKelasPolicy
{
    public function view(User $user, KegiatanKelas $kegiatan): Response
    {
        return KegiatanKelas::query()->visibleTo($user)->whereKey($kegiatan->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function kelola(User $user, KegiatanKelas $kegiatan): Response
    {
        $lihat = $this->view($user, $kegiatan);

        if ($lihat->denied() || $user->role === Role::SuperAdmin || $user->guru?->id === $kegiatan->guru_id) {
            return $lihat;
        }

        return Response::deny();
    }
}
