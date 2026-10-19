<?php

namespace App\Policies;

use App\Models\Murid;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Memakai scope `Murid::dataMuridUntuk` yang sama dengan daftar murid, sehingga daftar dan detail tidak
 * pernah berbeda pendapat. Murid di luar jangkauan dibalas 404 (B4).
 */
class MuridPolicy
{
    public function view(User $user, Murid $murid): Response
    {
        return Murid::query()->dataMuridUntuk($user)->whereKey($murid->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
