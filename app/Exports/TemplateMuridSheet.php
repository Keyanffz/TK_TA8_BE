<?php

namespace App\Exports;

use App\Services\ImportMuridService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class TemplateMuridSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithTitle
{
    /**
     * Excel mengubah angka panjang menjadi notasi ilmiah (NIK kehilangan digit terakhir, NISN kehilangan nol di
     * depan) dan tanggal mengikuti format lokal komputer, jadi kolom ini dibuat berformat teks.
     */
    private const KOLOM_TEKS = ['nisn', 'nik', 'tanggal_lahir', 'tanggal_masuk', 'tanggal_status'];

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Murid';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_keys(ImportMuridService::KOLOM);
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $format = [];

        foreach (self::KOLOM_TEKS as $kolom) {
            $huruf = Coordinate::stringFromColumnIndex((int) array_search($kolom, $this->headings(), true) + 1);
            $format["{$huruf}2:{$huruf}".(ImportMuridService::MAKSIMAL_BARIS + 1)] = NumberFormat::FORMAT_TEXT;
        }

        return $format;
    }
}
