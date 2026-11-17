<?php

namespace App\Enums;

/**
 * Rentang penghasilan per bulan, mengikuti rentang Dapodik.
 */
enum Penghasilan: string
{
    case DiBawah500Ribu = 'di_bawah_500rb';
    case Sampai1Juta = '500rb_1jt';
    case Sampai2Juta = '1jt_2jt';
    case Sampai5Juta = '2jt_5jt';
    case Sampai20Juta = '5jt_20jt';
    case DiAtas20Juta = 'di_atas_20jt';
    case TidakBerpenghasilan = 'tidak_berpenghasilan';

    public function label(): string
    {
        return match ($this) {
            self::DiBawah500Ribu => 'Kurang dari Rp500.000',
            self::Sampai1Juta => 'Rp500.000 - Rp999.999',
            self::Sampai2Juta => 'Rp1.000.000 - Rp1.999.999',
            self::Sampai5Juta => 'Rp2.000.000 - Rp4.999.999',
            self::Sampai20Juta => 'Rp5.000.000 - Rp20.000.000',
            self::DiAtas20Juta => 'Lebih dari Rp20.000.000',
            self::TidakBerpenghasilan => 'Tidak berpenghasilan',
        };
    }
}
