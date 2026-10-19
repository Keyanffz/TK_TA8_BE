<?php

namespace App\Exceptions;

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use RuntimeException;

/**
 * Guru tanpa izin modul yang dibutuhkan (A2.13). Dirender sebagai 403 FORBIDDEN dengan pesan yang menyebut izinnya,
 * supaya guru tahu apa yang harus diminta ke Kepala Sekolah.
 */
class IzinKurangException extends RuntimeException
{
    public static function untuk(ModulIzin $modul, LevelIzin $level): self
    {
        return new self("Anda belum punya izin {$level->value} {$modul->label()}. Minta Kepala Sekolah menambahkannya di halaman Hak Akses Guru.");
    }

    public static function khususKepalaSekolah(): self
    {
        return new self('Bagian ini khusus Kepala Sekolah.');
    }
}
