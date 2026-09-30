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

    /**
     * Path halaman login FE. Staff dan wali murid punya halaman login sendiri, jadi tautan di email dan kartu akun
     * harus mengikuti role penerimanya.
     */
    public function halamanLogin(): string
    {
        return match ($this) {
            self::SuperAdmin, self::Guru => '/staff/login',
            self::WaliMurid => '/login',
        };
    }
}
