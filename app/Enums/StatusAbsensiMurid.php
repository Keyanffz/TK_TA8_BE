<?php

namespace App\Enums;

/**
 * Status absensi harian murid yang dicatat guru kelas (A2.15).
 */
enum StatusAbsensiMurid: string
{
    case Hadir = 'hadir';
    case Izin = 'izin';
    case Sakit = 'sakit';
    case Alpa = 'alpa';

    public function label(): string
    {
        return match ($this) {
            self::Hadir => 'Hadir',
            self::Izin => 'Izin',
            self::Sakit => 'Sakit',
            self::Alpa => 'Alpa',
        };
    }
}
