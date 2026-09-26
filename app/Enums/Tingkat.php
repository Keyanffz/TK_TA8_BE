<?php

namespace App\Enums;

enum Tingkat: string
{
    case A = 'A';
    case B = 'B';

    public function label(): string
    {
        return match ($this) {
            self::A => 'TK A',
            self::B => 'TK B',
        };
    }
}
