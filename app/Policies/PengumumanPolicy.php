<?php

namespace App\Policies;

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Models\Pengumuman;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Pengumuman di luar feed pengguna dibalas 404. Mengubah dan menghapus hanya untuk penulisnya, Kepala Sekolah, dan
 * pemegang izin kelola pengumuman.
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

        if ($lihat->denied() || $user->punyaIzin(ModulIzin::Pengumuman, LevelIzin::Kelola) || $user->id === $pengumuman->penulis_id) {
            return $lihat;
        }

        return Response::deny();
    }
}
