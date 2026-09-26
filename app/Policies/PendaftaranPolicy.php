<?php

namespace App\Policies;

use App\Models\Pendaftaran;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Kepala Sekolah melihat semua pendaftaran; wali murid hanya miliknya. Lainnya dibalas 404.
 */
class PendaftaranPolicy
{
    public function view(User $user, Pendaftaran $pendaftaran): Response
    {
        return Pendaftaran::query()->visibleTo($user)->whereKey($pendaftaran->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
