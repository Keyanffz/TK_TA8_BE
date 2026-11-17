<?php

namespace App\Exports;

use App\Services\ImportMuridService;
use App\Support\KolomImportMurid;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Sheet Murid template import: judul kolom (kolom wajib diberi `*`), kolom teks, dan dropdown untuk kolom pilihan
 * yang merujuk daftar di sheet Petunjuk. Dropdown tidak memaksa: import tetap menerima kode dan memeriksa nilainya.
 */
class TemplateMuridSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithTitle
{
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
        return array_map(KolomImportMurid::judul(...), array_keys(KolomImportMurid::petunjuk()));
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $format = [];

        foreach (KolomImportMurid::teks() as $kolom) {
            $format[$this->rentang($kolom)] = NumberFormat::FORMAT_TEXT;
        }

        return $format;
    }

    /**
     * @return array<class-string, callable(AfterSheet): void>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                foreach (KolomImportMurid::pilihan() as $kolom => $enum) {
                    $validasi = (new DataValidation)
                        ->setType(DataValidation::TYPE_LIST)
                        ->setErrorStyle(DataValidation::STYLE_WARNING)
                        ->setAllowBlank(true)
                        ->setShowDropDown(true)
                        ->setShowErrorMessage(true)
                        ->setErrorTitle('Di luar daftar pilihan')
                        ->setError('Pilih dari daftar di sheet Petunjuk.')
                        ->setFormula1(KolomImportMurid::rentangPilihan($enum));

                    $event->sheet->getDelegate()->setDataValidation($this->rentang($kolom), $validasi);
                }
            },
        ];
    }

    private function rentang(string $kolom): string
    {
        $huruf = Coordinate::stringFromColumnIndex((int) array_search($kolom, array_keys(KolomImportMurid::petunjuk()), true) + 1);

        return "{$huruf}2:{$huruf}".(ImportMuridService::MAKSIMAL_BARIS + 1);
    }
}
