<?php

namespace App\Enums;

enum PeriodeTagihan: string
{
    case Bulanan = 'bulanan';
    case Sekali = 'sekali';

    public function label(): string
    {
        return match ($this) {
            self::Bulanan => 'Bulanan',
            self::Sekali => 'Sekali Bayar',
        };
    }
}
