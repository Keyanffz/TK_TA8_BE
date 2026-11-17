<?php

namespace App\Enums;

enum StatusOrangTua: string
{
    case Kandung = 'kandung';
    case Tiri = 'tiri';
    case Angkat = 'angkat';

    public function label(): string
    {
        return match ($this) {
            self::Kandung => 'Kandung',
            self::Tiri => 'Tiri',
            self::Angkat => 'Angkat',
        };
    }
}
