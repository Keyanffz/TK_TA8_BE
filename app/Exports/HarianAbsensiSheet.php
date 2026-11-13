<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;

/**
 * Sheet Harian ekspor rekap absensi: satu baris per peserta per hari, urut tanggal lalu nama. Tanggal ditulis sebagai
 * tanggal Excel (bukan teks) supaya bisa disaring dan diurutkan, tampil hari/bulan/tahun.
 */
class HarianAbsensiSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithTitle
{
    public const JUDUL_KOLOM = ['Tanggal', 'Hari', 'Nama', 'Jabatan', 'Jam Masuk', 'Jam Pulang', 'Status', 'Keterangan'];

    /**
     * @param  list<array{user: array{id: int, nama: string, jabatan: string|null}, hari: list<array{tanggal: CarbonInterface, jam_masuk: string, jam_pulang: string, status: string, keterangan: string}>}>  $harian  peserta urut nama
     */
    public function __construct(private readonly array $harian) {}

    /**
     * Peserta sudah urut nama, dan pengurutan PHP stabil, jadi baris bertanggal sama tetap urut nama.
     *
     * @return list<list<string|float>>
     */
    public function array(): array
    {
        return collect($this->harian)
            ->flatMap(fn (array $peserta): array => array_map(fn (array $hari): array => [
                TanggalExcel::dateTimeToExcel($hari['tanggal']),
                $hari['tanggal']->translatedFormat('l'),
                $peserta['user']['nama'],
                $peserta['user']['jabatan'] ?? '',
                $hari['jam_masuk'],
                $hari['jam_pulang'],
                $hari['status'],
                $hari['keterangan'],
            ], $peserta['hari']))
            ->sortBy(0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['A' => 'dd/mm/yyyy'];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return self::JUDUL_KOLOM;
    }

    public function title(): string
    {
        return 'Harian';
    }
}
