<?php

namespace App\Enums;

/**
 * `menunggu` dan `ditolak` hanya ada di riwayat transfer lama; sejak pembayaran dicatat tunai oleh petugas,
 * pembayaran baru langsung `diterima` dan hanya bisa berubah menjadi `dibatalkan`.
 */
enum StatusPembayaran: string
{
    case Menunggu = 'menunggu';
    case Diterima = 'diterima';
    case Ditolak = 'ditolak';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu Verifikasi',
            self::Diterima => 'Diterima',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
