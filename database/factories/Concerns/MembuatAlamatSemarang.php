<?php

namespace Database\Factories\Concerns;

trait MembuatAlamatSemarang
{
    private const KECAMATAN_SEMARANG = [
        'Semarang Tengah', 'Semarang Utara', 'Semarang Timur', 'Semarang Selatan', 'Semarang Barat',
        'Gayamsari', 'Candisari', 'Gajahmungkur', 'Genuk', 'Pedurungan', 'Tembalang', 'Banyumanik',
        'Gunungpati', 'Mijen', 'Ngaliyan', 'Tugu',
    ];

    private const JALAN_SEMARANG = [
        'Jl. Pandanaran', 'Jl. Kedungmundu', 'Jl. Fatmawati', 'Jl. Majapahit', 'Jl. Sriwijaya',
        'Jl. Setiabudi', 'Jl. Ngesrep Timur', 'Jl. Tlogosari Raya', 'Jl. Dr. Cipto', 'Jl. Kaligawe',
        'Jl. Supriyadi', 'Jl. Wolter Monginsidi', 'Jl. Prof. Hamka', 'Jl. Beringin', 'Jl. Mulawarman',
    ];

    protected function alamatSemarang(): string
    {
        return sprintf(
            '%s No. %d, RT %02d/RW %02d, Kec. %s, Kota Semarang',
            fake()->randomElement(self::JALAN_SEMARANG),
            fake()->numberBetween(1, 150),
            fake()->numberBetween(1, 12),
            fake()->numberBetween(1, 9),
            fake()->randomElement(self::KECAMATAN_SEMARANG),
        );
    }
}
