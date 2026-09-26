<?php

namespace App\Enums;

enum StatusPendaftaran: string
{
    case Diajukan = 'diajukan';
    case Diverifikasi = 'diverifikasi';
    case Diterima = 'diterima';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Diajukan',
            self::Diverifikasi => 'Diverifikasi',
            self::Diterima => 'Diterima',
            self::Ditolak => 'Ditolak',
        };
    }
}
