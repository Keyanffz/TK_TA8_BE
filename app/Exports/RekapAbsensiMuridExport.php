<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * `GET /kelas/{id}/absensi-murid/rekap/export?format=xlsx`. Tanpa `WithStrictNullComparison`, angka 0 ditulis sebagai
 * sel kosong.
 */
class RekapAbsensiMuridExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
{
    /**
     * @param  list<array{murid: array{id: int, nis: string, nama_lengkap: string}, hadir: int, izin: int, sakit: int, alpa: int}>  $murid
     */
    public function __construct(private readonly array $murid) {}

    /**
     * @return list<list<string|int>>
     */
    public function array(): array
    {
        return array_map(fn (array $baris): array => [
            $baris['murid']['nis'], $baris['murid']['nama_lengkap'], $baris['hadir'], $baris['izin'], $baris['sakit'], $baris['alpa'],
        ], $this->murid);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['NIS', 'Nama', 'Hadir', 'Izin', 'Sakit', 'Alpa'];
    }

    public function title(): string
    {
        return 'Absensi Murid';
    }
}
