<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * `GET /absensi/rekap/export?format=xlsx`: sheet Rekap Absensi berisi jumlah per peserta, sheet Harian berisi jam
 * masuk dan jam pulang setiap peserta per hari, seperti buku absen di sekolah. Menggantikan CSV yang kolomnya
 * menyatu di Excel berpengaturan Indonesia (pemisah daftar titik koma); .xlsx menyimpan sel, jadi tidak bergantung
 * pemisah.
 */
class RekapAbsensiExport implements Export, WithMultipleSheets
{
    /**
     * @param  list<array{user: array{id: int, nama: string, jabatan: string|null}, hadir: int, terlambat: int, izin: int, sakit: int, tidak_hadir: int, tidak_absen_pulang: int}>  $rekap
     * @param  list<array{user: array{id: int, nama: string, jabatan: string|null}, hari: list<array{tanggal: CarbonInterface, jam_masuk: string, jam_pulang: string, status: string, keterangan: string}>}>  $harian
     */
    public function __construct(private readonly array $rekap, private readonly array $harian) {}

    /**
     * @return list<Export>
     */
    public function sheets(): array
    {
        return [new RekapAbsensiSheet($this->rekap), new HarianAbsensiSheet($this->harian)];
    }
}
