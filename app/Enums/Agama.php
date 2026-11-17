<?php

namespace App\Enums;

/**
 * Nilai disimpan sama dengan labelnya, karena kolom agama murid lama berisi teks yang sama dari dropdown FE.
 * Kolom `agama` tidak di-cast ke enum supaya teks lama di luar daftar ini tetap terbaca.
 */
enum Agama: string
{
    case Islam = 'Islam';
    case Kristen = 'Kristen';
    case Katolik = 'Katolik';
    case Hindu = 'Hindu';
    case Buddha = 'Buddha';
    case Konghucu = 'Konghucu';

    public function label(): string
    {
        return $this->value;
    }
}
