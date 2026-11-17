<?php

namespace App\Enums;

enum StatusYatimPiatu: string
{
    case Tidak = 'tidak';
    case Yatim = 'yatim';
    case Piatu = 'piatu';
    case YatimPiatu = 'yatim_piatu';

    public function label(): string
    {
        return match ($this) {
            self::Tidak => 'Tidak',
            self::Yatim => 'Yatim',
            self::Piatu => 'Piatu',
            self::YatimPiatu => 'Yatim piatu',
        };
    }

    /**
     * Data ayah atau ibu yang sudah meninggal cukup berisi status dan nama.
     */
    public function sudahMeninggal(Hubungan $peran): bool
    {
        return match ($peran) {
            Hubungan::Ayah => in_array($this, [self::Yatim, self::YatimPiatu], true),
            Hubungan::Ibu => in_array($this, [self::Piatu, self::YatimPiatu], true),
            Hubungan::Wali => false,
        };
    }
}
