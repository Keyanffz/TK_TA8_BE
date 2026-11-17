<?php

namespace App\Enums;

enum PendidikanTerakhir: string
{
    case TidakSekolah = 'tidak_sekolah';
    case Sd = 'sd';
    case Smp = 'smp';
    case Slta = 'slta';
    case D1D3 = 'd1_d3';
    case D4S1 = 'd4_s1';
    case S2 = 's2';
    case S3 = 's3';

    public function label(): string
    {
        return match ($this) {
            self::TidakSekolah => 'Tidak sekolah',
            self::Sd => 'SD',
            self::Smp => 'SMP',
            self::Slta => 'SLTA',
            self::D1D3 => 'D1-D3',
            self::D4S1 => 'D4/S1',
            self::S2 => 'S2',
            self::S3 => 'S3',
        };
    }
}
