<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * `GET /laporan/keuangan/export`: satu sheet rincian tagihan (menurut jatuh tempo) dan satu sheet
 * pembayaran diterima (menurut tanggal bayar) dalam rentang yang sama dengan laporan keuangan.
 */
class LaporanKeuanganExport implements Export, WithMultipleSheets
{
    public function __construct(private readonly Carbon $dari, private readonly Carbon $sampai) {}

    /**
     * @return list<Export>
     */
    public function sheets(): array
    {
        return [
            new TagihanSheet($this->dari, $this->sampai),
            new PembayaranSheet($this->dari, $this->sampai),
        ];
    }
}
