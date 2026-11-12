<?php

namespace App\Policies;

use App\Enums\ModulIzin;
use App\Enums\Role;
use App\Models\Pembayaran;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Menu pembayaran untuk pemegang izin lihat keuangan (semua pembayaran) dan wali murid (pembayaran tagihan anaknya).
 * Guru tanpa izin keuangan membuka pembayaran murid kelasnya satu per satu lewat detail tagihan, misalnya wali
 * kelas yang mencetak kwitansi pembayaran yang baru dicatatnya.
 */
class PembayaranPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->punyaIzin(ModulIzin::Keuangan) || $user->role === Role::WaliMurid
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Pembayaran di luar jangkauan dibalas 404 (B4).
     */
    public function view(User $user, Pembayaran $pembayaran): Response
    {
        return Pembayaran::query()->visibleTo($user)->whereKey($pembayaran->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Bukti transfer di riwayat lama: guru tanpa izin keuangan ditolak 403 untuk pembayaran murid kelasnya.
     */
    public function lihatBukti(User $user, Pembayaran $pembayaran): Response
    {
        $lihat = $this->view($user, $pembayaran);

        return $lihat->denied() ? $lihat : $this->viewAny($user);
    }
}
