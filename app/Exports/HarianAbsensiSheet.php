<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Sheet Harian ekspor rekap absensi: satu baris per peserta per hari absensi, urut tanggal lalu nama.
 */
class HarianAbsensiSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public const JUDUL_KOLOM = ['Tanggal', 'Hari', 'Nama', 'Jabatan', 'Jam Masuk', 'Jam Pulang', 'Status', 'Keterangan'];

    /**
     * @param  list<array{user: array{id: int, nama: string, jabatan: string|null}, hari: list<array{tanggal: CarbonInterface, jam_masuk: string, jam_pulang: string, status: string, keterangan: string}>}>  $harian  peserta urut nama
     */
    public function __construct(private readonly array $harian) {}

    /**
     * Peserta sudah urut nama, dan pengurutan PHP stabil, jadi baris bertanggal sama tetap urut nama.
     *
     * @return list<list<string>>
     */
    public function array(): array
    {
        return collect($this->harian)
            ->flatMap(fn (array $peserta): array => array_map(fn (array $hari): array => [
                $hari['tanggal']->toDateString(),
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
