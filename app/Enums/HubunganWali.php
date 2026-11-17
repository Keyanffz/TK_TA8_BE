<?php

namespace App\Enums;

/**
 * Hubungan wali (selain ayah dan ibu) dengan anak di formulir data murid.
 */
enum HubunganWali: string
{
    case Kakek = 'kakek';
    case Nenek = 'nenek';
    case Paman = 'paman';
    case Bibi = 'bibi';
    case Kakak = 'kakak';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Kakek => 'Kakek',
            self::Nenek => 'Nenek',
            self::Paman => 'Paman',
            self::Bibi => 'Bibi',
            self::Kakak => 'Kakak',
            self::Lainnya => 'Lainnya',
        };
    }
}
