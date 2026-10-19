<?php

namespace App\Enums;

/**
 * Kumpulan izin yang sering diberikan bersama. Menerapkan preset mengganti seluruh izin guru dengan isi preset;
 * setelah itu izin per modul masih bisa disesuaikan.
 */
enum PresetIzin: string
{
    case Bendahara = 'bendahara';
    case AdminWebsite = 'admin_website';
    case AdminAbsensi = 'admin_absensi';
    case TataUsaha = 'tata_usaha';

    public function label(): string
    {
        return match ($this) {
            self::Bendahara => 'Bendahara',
            self::AdminWebsite => 'Admin Website',
            self::AdminAbsensi => 'Admin Absensi',
            self::TataUsaha => 'Tata Usaha',
        };
    }

    /**
     * @return array<string, LevelIzin> level per nilai `ModulIzin`; modul yang tidak disebut tidak diberi izin
     */
    public function izin(): array
    {
        return match ($this) {
            self::Bendahara => [ModulIzin::Keuangan->value => LevelIzin::Kelola],
            self::AdminWebsite => [ModulIzin::KontenWebsite->value => LevelIzin::Kelola, ModulIzin::Pengumuman->value => LevelIzin::Kelola],
            self::AdminAbsensi => [ModulIzin::LaporanAbsensi->value => LevelIzin::Kelola],
            self::TataUsaha => [ModulIzin::DataMurid->value => LevelIzin::Kelola],
        };
    }
}
