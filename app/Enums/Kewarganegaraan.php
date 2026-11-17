<?php

namespace App\Enums;

enum Kewarganegaraan: string
{
    case Wni = 'wni';
    case Wna = 'wna';

    public function label(): string
    {
        return match ($this) {
            self::Wni => 'WNI',
            self::Wna => 'WNA',
        };
    }
}
