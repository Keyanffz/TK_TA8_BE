<?php

namespace App\Enums;

enum TargetPengumuman: string
{
    case Semua = 'semua';
    case Guru = 'guru';
    case WaliMurid = 'wali_murid';
    case Kelas = 'kelas';
    case Murid = 'murid';

    public function label(): string
    {
        return match ($this) {
            self::Semua => 'Semua',
            self::Guru => 'Guru',
            self::WaliMurid => 'Wali Murid',
            self::Kelas => 'Kelas',
            self::Murid => 'Murid',
        };
    }
}
