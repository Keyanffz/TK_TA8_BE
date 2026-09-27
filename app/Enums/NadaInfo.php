<?php

namespace App\Enums;

/**
 * Nada banner info sekolah di beranda wali murid (`beranda.info_wali`), menentukan warnanya di FE.
 */
enum NadaInfo: string
{
    case Info = 'info';
    case Penting = 'penting';
    case Peringatan = 'peringatan';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Penting => 'Penting',
            self::Peringatan => 'Peringatan',
        };
    }
}
