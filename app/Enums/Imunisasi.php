<?php

namespace App\Enums;

enum Imunisasi: string
{
    case Bcg = 'bcg';
    case HepatitisB = 'hepatitis_b';
    case Polio = 'polio';
    case Dpt = 'dpt';
    case CampakMr = 'campak_mr';

    public function label(): string
    {
        return match ($this) {
            self::Bcg => 'BCG',
            self::HepatitisB => 'Hepatitis B',
            self::Polio => 'Polio',
            self::Dpt => 'DPT',
            self::CampakMr => 'Campak/MR',
        };
    }
}
