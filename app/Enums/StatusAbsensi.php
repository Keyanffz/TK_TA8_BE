<?php

namespace App\Enums;

/**
 * Status absen masuk. Absen pulang tidak punya status.
 */
enum StatusAbsensi: string
{
    case Hadir = 'hadir';
    case Terlambat = 'terlambat';
    case TidakHadir = 'tidak_hadir';
    case Izin = 'izin';
    case Sakit = 'sakit';

    public function label(): string
    {
        return match ($this) {
            self::Hadir => 'Hadir',
            self::Terlambat => 'Terlambat',
            self::TidakHadir => 'Tidak Hadir',
            self::Izin => 'Izin',
            self::Sakit => 'Sakit',
        };
    }

    /**
     * Izin atau sakit: dicatat peserta tanpa lokasi, dan hari itu tidak ada absen pulang.
     */
    public function berhalangan(): bool
    {
        return $this === self::Izin || $this === self::Sakit;
    }

    /**
     * Hanya peserta yang datang ke sekolah yang absen pulang.
     */
    public function perluAbsenPulang(): bool
    {
        return $this === self::Hadir || $this === self::Terlambat;
    }
}
