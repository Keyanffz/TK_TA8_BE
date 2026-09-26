<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Guru = 'guru';
    case WaliMurid = 'wali_murid';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Kepala Sekolah',
            self::Guru => 'Guru',
            self::WaliMurid => 'Wali Murid',
        };
    }
}
