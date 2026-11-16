<?php

namespace App\Exports;

use App\Models\Murid;
use App\Services\NisMuridService;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * `GET /murid/nis/template`: murid aktif yang menunggu NIS dengan kolom `nis` kosong untuk diisi Kepala Sekolah.
 * Kolom `nis` berformat teks supaya angka nol di depan NIS tidak hilang.
 */
class TemplateNisMuridExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, Murid>  $murid
     */
    public function __construct(private readonly Collection $murid) {}

    /**
     * @return list<list<int|string|null>>
     */
    public function array(): array
    {
        return $this->murid->map(fn (Murid $satu): array => [$satu->id, $satu->nama_lengkap, $satu->tanggal_lahir->translatedFormat('j F Y'), null])->values()->all();
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
        return NisMuridService::KOLOM;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['D2:D'.(NisMuridService::MAKSIMAL_BARIS + 1) => NumberFormat::FORMAT_TEXT];
    }
}
