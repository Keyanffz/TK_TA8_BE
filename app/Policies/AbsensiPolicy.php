<?php

namespace App\Policies;

use App\Models\Absensi;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AbsensiPolicy
{
    /**
     * Absensi dan fotonya hanya untuk pemiliknya, Kepala Sekolah, dan pemegang izin lihat laporan absensi; guru lain
     * dibalas 404 (B4).
     */
    public function view(User $user, Absensi $absensi): Response
    {
        return Absensi::query()->visibleTo($user)->whereKey($absensi->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
