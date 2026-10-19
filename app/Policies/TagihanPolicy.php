<?php

namespace App\Policies;

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Enums\Role;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Pemegang izin lihat keuangan melihat semua tagihan; guru lain hanya tagihan murid kelasnya (read-only); wali hanya
 * tagihan anaknya. Tagihan di luar jangkauan dibalas 404 (B4).
 */
class TagihanPolicy
{
    public function view(User $user, Tagihan $tagihan): Response
    {
        return Tagihan::query()->visibleTo($user)->whereKey($tagihan->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Wali mengunggah bukti transfer untuk tagihan anaknya; pemegang izin kelola keuangan mencatat pembayaran tunai
     * atau transfer. Guru tanpa izin kelola keuangan hanya bisa melihat (403 untuk tagihan yang terlihat, 404 untuk
     * lainnya).
     */
    public function bayar(User $user, Tagihan $tagihan): Response
    {
        $lihat = $this->view($user, $tagihan);

        if ($lihat->denied() || $user->punyaIzin(ModulIzin::Keuangan, LevelIzin::Kelola) || $user->role === Role::WaliMurid) {
            return $lihat;
        }

        return Response::deny();
    }
}
