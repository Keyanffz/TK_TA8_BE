<?php

namespace App\Enums;

enum TipeKeringanan: string
{
    case Persen = 'persen';
    case Nominal = 'nominal';

    public function label(): string
    {
        return match ($this) {
            self::Persen => 'Persen',
            self::Nominal => 'Nominal',
        };
    }
}
