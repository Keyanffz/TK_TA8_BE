<?php

namespace App\Exports;

use App\Services\ImportMuridService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class PetunjukImportMuridSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        $baris = [];

        foreach (ImportMuridService::KOLOM as $kolom => [$wajib, $isi]) {
            $baris[] = [$kolom, $wajib, $isi];
        }

        return $baris;
    }

    public function title(): string
    {
        return 'Petunjuk';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Kolom', 'Wajib', 'Isi'];
    }
}
