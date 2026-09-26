<?php

namespace App\Enums;

enum StatusTagihan: string
{
    case BelumBayar = 'belum_bayar';
    case MenungguVerifikasi = 'menunggu_verifikasi';
    case Lunas = 'lunas';
    case Terlambat = 'terlambat';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::BelumBayar => 'Belum Bayar',
            self::MenungguVerifikasi => 'Menunggu Verifikasi',
            self::Lunas => 'Lunas',
            self::Terlambat => 'Terlambat',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
