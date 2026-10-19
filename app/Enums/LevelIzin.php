<?php

namespace App\Enums;

enum LevelIzin: string
{
    case Lihat = 'lihat';
    case Kelola = 'kelola';

    public function label(): string
    {
        return match ($this) {
            self::Lihat => 'Lihat',
            self::Kelola => 'Kelola',
        };
    }

    /**
     * Izin kelola selalu mencakup izin lihat modul yang sama.
     */
    public function mencakup(self $perlu): bool
    {
        return $this === self::Kelola || $perlu === self::Lihat;
    }
}
