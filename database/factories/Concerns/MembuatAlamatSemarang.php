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

    /** Kelurahan di Kota Semarang beserta kecamatannya. */
    private const KELURAHAN_SEMARANG = [
        'Tlogosari Kulon' => 'Pedurungan', 'Pedurungan Kidul' => 'Pedurungan', 'Sendangmulyo' => 'Tembalang',
        'Srondol Wetan' => 'Banyumanik', 'Pleburan' => 'Semarang Selatan', 'Bendan Ngisor' => 'Gajahmungkur',
        'Jatingaleh' => 'Candisari', 'Kaligawe' => 'Gayamsari', 'Ngaliyan' => 'Ngaliyan', 'Gisikdrono' => 'Semarang Barat',
    ];

    /**
     * Alamat formulir data murid: jalan dan RT/RW, lalu kelurahan, kecamatan, kota, dan provinsi terpisah.
     *
     * @return array{alamat: string, desa_kelurahan: string, kecamatan: string, kabupaten_kota: string, provinsi: string}
     */
    protected function alamatFormulirSemarang(): array
    {
        $kelurahan = fake()->randomElement(array_keys(self::KELURAHAN_SEMARANG));

        return [
            'alamat' => sprintf('%s No. %d, RT %02d/RW %02d', fake()->randomElement(self::JALAN_SEMARANG), fake()->numberBetween(1, 150), fake()->numberBetween(1, 12), fake()->numberBetween(1, 9)),
            'desa_kelurahan' => $kelurahan,
            'kecamatan' => self::KELURAHAN_SEMARANG[$kelurahan],
            'kabupaten_kota' => 'Kota Semarang',
            'provinsi' => 'Jawa Tengah',
        ];
    }

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
