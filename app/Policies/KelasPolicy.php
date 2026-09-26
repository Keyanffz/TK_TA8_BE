<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Kelas;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Batas per role dijaga middleware `role:` di route; policy ini menjaga per data. Kelas yang tidak
 * diampu dibalas 404 supaya keberadaannya tidak bocor (B4).
 */
class KelasPolicy
{
    public function view(User $user, Kelas $kelas): Response
    {
        $boleh = match ($user->role) {
            Role::SuperAdmin => true,
            Role::Guru => Kelas::query()->diampuOleh($user)->whereKey($kelas->id)->exists(),
            Role::WaliMurid => false,
        };

        return $boleh ? Response::allow() : Response::denyAsNotFound();
    }
}
