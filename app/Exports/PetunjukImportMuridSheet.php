<?php

namespace App\Exports;

use App\Support\KolomImportMurid;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Kolom A-C: arti tiap kolom dan apakah wajib. Mulai kolom E: daftar pilihan, sumber dropdown di sheet Murid.
 */
class PetunjukImportMuridSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        $kolom = [];

        foreach (KolomImportMurid::petunjuk() as $nama => $isi) {
            $kolom[] = [$nama, $this->wajib($nama), $isi];
        }

        $pilihan = array_map(
            fn (string $enum): array => array_map(fn ($satu): string => $satu->label(), $enum::cases()),
            array_keys(KolomImportMurid::DAFTAR_PILIHAN),
        );
        $baris = [];

        for ($i = 0; $i < max(count($kolom), ...array_map('count', $pilihan)); $i++) {
            $baris[] = [...($kolom[$i] ?? ['', '', '']), '', ...array_map(fn (array $daftar): string => $daftar[$i] ?? '', $pilihan)];
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
        return ['Kolom', 'Wajib', 'Isi', '', ...array_values(KolomImportMurid::DAFTAR_PILIHAN)];
    }

    private function wajib(string $kolom): string
    {
        return match (true) {
            in_array($kolom, KolomImportMurid::WAJIB, true) => 'Ya',
            $kolom === 'tahun_lulus' => 'Untuk status lulus',
            default => 'Tidak',
        };
    }
}
