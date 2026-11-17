<?php

namespace App\Enums;

/**
 * Pilihan pekerjaan orang tua, disederhanakan dari daftar Dapodik.
 */
enum Pekerjaan: string
{
    case TidakBekerja = 'tidak_bekerja';
    case MengurusRumahTangga = 'mengurus_rumah_tangga';
    case PnsTniPolri = 'pns_tni_polri';
    case KaryawanSwasta = 'karyawan_swasta';
    case KaryawanBumn = 'karyawan_bumn';
    case Wiraswasta = 'wiraswasta';
    case Pedagang = 'pedagang';
    case PetaniPeternak = 'petani_peternak';
    case Nelayan = 'nelayan';
    case Buruh = 'buruh';
    case Pensiunan = 'pensiunan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::TidakBekerja => 'Tidak bekerja',
            self::MengurusRumahTangga => 'Mengurus rumah tangga',
            self::PnsTniPolri => 'PNS/TNI/Polri',
            self::KaryawanSwasta => 'Karyawan swasta',
            self::KaryawanBumn => 'Karyawan BUMN',
            self::Wiraswasta => 'Wiraswasta',
            self::Pedagang => 'Pedagang',
            self::PetaniPeternak => 'Petani/Peternak',
            self::Nelayan => 'Nelayan',
            self::Buruh => 'Buruh',
            self::Pensiunan => 'Pensiunan',
            self::Lainnya => 'Lainnya',
        };
    }
}
