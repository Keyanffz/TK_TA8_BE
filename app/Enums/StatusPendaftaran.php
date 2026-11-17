<?php

namespace App\Enums;

enum StatusPendaftaran: string
{
    case Diajukan = 'diajukan';
    case Diverifikasi = 'diverifikasi';
    case Diterima = 'diterima';
    case Ditolak = 'ditolak';
    case MengundurkanDiri = 'mengundurkan_diri';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Diajukan',
            self::Diverifikasi => 'Diverifikasi',
            self::Diterima => 'Diterima',
            self::Ditolak => 'Ditolak',
            self::MengundurkanDiri => 'Mengundurkan diri',
        };
    }
}
