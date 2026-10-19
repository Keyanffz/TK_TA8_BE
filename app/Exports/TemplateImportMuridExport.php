<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * `GET /murid/import/template`: sheet Murid berisi judul kolom untuk diisi, sheet Petunjuk berisi aturan tiap kolom.
 * Import hanya membaca sheet pertama.
 */
class TemplateImportMuridExport implements Export, WithMultipleSheets
{
    /**
     * @return list<Export>
     */
    public function sheets(): array
    {
        return [new TemplateMuridSheet, new PetunjukImportMuridSheet];
    }
}
