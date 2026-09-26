<?php

namespace App\Policies;

use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Petugas keuangan melihat semua tagihan; guru lain hanya tagihan murid kelasnya (read-only); wali hanya
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
}
