<?php

namespace App\Enums;

enum GolonganDarah: string
{
    case A = 'A';
    case B = 'B';
    case AB = 'AB';
    case O = 'O';
    case TidakTahu = 'tidak_tahu';

    public function label(): string
    {
        return match ($this) {
            self::TidakTahu => 'Tidak tahu',
            default => $this->value,
        };
    }
}
