<?php

namespace App\Enums;

enum StatusPembayaran: string
{
    case Menunggu = 'menunggu';
    case Diterima = 'diterima';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu Verifikasi',
            self::Diterima => 'Diterima',
            self::Ditolak => 'Ditolak',
        };
    }
}
