<?php

namespace App\Enums;

/**
 * Status absen masuk. Absen pulang tidak punya status.
 */
enum StatusAbsensi: string
{
    case Hadir = 'hadir';
    case Terlambat = 'terlambat';
    case TidakHadir = 'tidak_hadir';

    public function label(): string
    {
        return match ($this) {
            self::Hadir => 'Hadir',
            self::Terlambat => 'Terlambat',
            self::TidakHadir => 'Tidak Hadir',
        };
    }
}
