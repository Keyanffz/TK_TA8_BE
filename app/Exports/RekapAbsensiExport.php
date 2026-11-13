<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * `GET /absensi/rekap/export?format=xlsx`. Menggantikan CSV yang kolomnya menyatu di Excel berpengaturan
 * Indonesia (pemisah daftar titik koma); .xlsx menyimpan sel, jadi tidak bergantung pemisah. Tanpa
 * `WithStrictNullComparison`, angka 0 ditulis sebagai sel kosong.
 */
class RekapAbsensiExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
{
    public const JUDUL_KOLOM = ['Nama', 'Jabatan', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Tidak Hadir', 'Tidak Absen Pulang'];

    /**
     * @param  list<array{user: array{id: int, nama: string, jabatan: string|null}, hadir: int, terlambat: int, izin: int, sakit: int, tidak_hadir: int, tidak_absen_pulang: int}>  $rekap
     */
    public function __construct(private readonly array $rekap) {}

    /**
     * @return list<list<string|int>>
     */
    public function array(): array
    {
        return array_map(fn (array $baris): array => [
            $baris['user']['nama'], $baris['user']['jabatan'] ?? '',
            $baris['hadir'], $baris['terlambat'], $baris['izin'], $baris['sakit'], $baris['tidak_hadir'], $baris['tidak_absen_pulang'],
        ], $this->rekap);
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
        return 'Rekap Absensi';
    }
}
