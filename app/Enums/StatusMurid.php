<?php

namespace App\Enums;

enum StatusMurid: string
{
    case Aktif = 'aktif';
    case Lulus = 'lulus';
    case Pindah = 'pindah';
    case Keluar = 'keluar';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Lulus => 'Lulus',
            self::Pindah => 'Pindah',
            self::Keluar => 'Keluar',
        };
    }
}
