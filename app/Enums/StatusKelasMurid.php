<?php

namespace App\Enums;

enum StatusKelasMurid: string
{
    case Aktif = 'aktif';
    case Naik = 'naik';
    case Tinggal = 'tinggal';
    case Lulus = 'lulus';
    case Keluar = 'keluar';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Naik => 'Naik Kelas',
            self::Tinggal => 'Tinggal Kelas',
            self::Lulus => 'Lulus',
            self::Keluar => 'Keluar',
        };
    }
}
