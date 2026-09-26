<?php

namespace App\Enums;

enum JenisDokumen: string
{
    case AktaKelahiran = 'akta_kelahiran';
    case KartuKeluarga = 'kartu_keluarga';
    case PasFoto = 'pas_foto';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::AktaKelahiran => 'Akta Kelahiran',
            self::KartuKeluarga => 'Kartu Keluarga',
            self::PasFoto => 'Pas Foto',
            self::Lainnya => 'Lainnya',
        };
    }
}
