<?php

namespace App\Enums;

use LogicException;

enum StatusAkun: string
{
    case Pending = 'pending';
    case Aktif = 'aktif';
    case Ditolak = 'ditolak';
    case Nonaktif = 'nonaktif';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Persetujuan',
            self::Aktif => 'Aktif',
            self::Ditolak => 'Ditolak',
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
            self::Pending => KodeError::AccountPending,
            self::Ditolak => KodeError::AccountRejected,
            self::Nonaktif => KodeError::AccountInactive,
            self::Aktif => throw new LogicException('Akun aktif tidak punya kode penolakan akses.'),
        };
    }

    public function pesanAksesDitolak(): string
    {
        return match ($this) {
            self::Pending => 'Akun Anda masih menunggu persetujuan Kepala Sekolah.',
            self::Ditolak => 'Pendaftaran akun Anda ditolak.',
            self::Nonaktif => 'Akun Anda sudah dinonaktifkan. Hubungi pihak sekolah untuk mengaktifkannya kembali.',
            self::Aktif => throw new LogicException('Akun aktif tidak punya pesan penolakan akses.'),
        };
    }
}
