<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Pembayaran;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Menu pembayaran untuk petugas keuangan (semua pembayaran) dan wali murid (pembayaran tagihan anaknya).
 * Guru tanpa izin keuangan hanya melihat riwayat pembayaran lewat detail tagihan.
 */
class PembayaranPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->bisaKelolaKeuangan() || $user->role === Role::WaliMurid
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Pembayaran di luar jangkauan dibalas 404; guru tanpa izin keuangan ditolak 403 untuk pembayaran
     * murid kelasnya.
     */
    public function view(User $user, Pembayaran $pembayaran): Response
    {
        if (! Pembayaran::query()->visibleTo($user)->whereKey($pembayaran->id)->exists()) {
            return Response::denyAsNotFound();
        }

        return $this->viewAny($user);
    }
}
