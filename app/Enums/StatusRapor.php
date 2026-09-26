<?php

namespace App\Enums;

enum StatusRapor: string
{
    case Draft = 'draft';
    case Diajukan = 'diajukan';
    case Revisi = 'revisi';
    case Terbit = 'terbit';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Diajukan => 'Diajukan',
            self::Revisi => 'Revisi',
            self::Terbit => 'Terbit',
        };
    }
}
