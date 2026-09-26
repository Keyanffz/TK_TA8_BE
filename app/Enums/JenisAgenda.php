<?php

namespace App\Enums;

enum JenisAgenda: string
{
    case Kegiatan = 'kegiatan';
    case Libur = 'libur';
    case Rapat = 'rapat';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Kegiatan => 'Kegiatan',
            self::Libur => 'Libur',
            self::Rapat => 'Rapat',
            self::Lainnya => 'Lainnya',
        };
    }
}
