<?php

namespace App\Enums;

use LogicException;

enum StatusAkun: string
{
    case Aktif = 'aktif';
    case Nonaktif = 'nonaktif';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Nonaktif => 'Nonaktif',
        };
    }

    /**
     * Kode error saat akun berstatus ini mencoba mengakses API.
     * Dipakai middleware EnsureAccountActive dan alur login.
     */
    public function kodeErrorAkses(): KodeError
    {
        return match ($this) {
            self::Nonaktif => KodeError::AccountInactive,
            self::Aktif => throw new LogicException('Akun aktif tidak punya kode penolakan akses.'),
        };
    }

    public function pesanAksesDitolak(): string
    {
        return match ($this) {
            self::Nonaktif => 'Akun Anda sudah dinonaktifkan. Hubungi pihak sekolah untuk mengaktifkannya kembali.',
            self::Aktif => throw new LogicException('Akun aktif tidak punya pesan penolakan akses.'),
        };
    }
}
