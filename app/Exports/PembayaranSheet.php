<?php

namespace App\Exports;

use App\Models\Pembayaran;
use App\Services\LaporanKeuanganService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * @implements WithMapping<Pembayaran>
 */
class PembayaranSheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private readonly Carbon $dari, private readonly Carbon $sampai) {}

    /**
     * @return Builder<Pembayaran>
     */
    public function query(): Builder
    {
        return app(LaporanKeuanganService::class)->pemasukan($this->dari, $this->sampai)
            ->with(['tagihan.jenisTagihan', 'tagihan.murid', 'verifikator'])
            ->orderBy('tanggal_bayar')
            ->orderBy('kode');
    }

    public function title(): string
    {
        return 'Pembayaran Diterima';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Kode', 'Tanggal Bayar', 'Kode Tagihan', 'Nama Murid', 'Tagihan', 'Metode', 'Bank Pengirim', 'Jumlah', 'Diverifikasi Oleh'];
    }

    /**
     * @param  Pembayaran  $row
     * @return list<string|int|null>
     */
    public function map($row): array
    {
        return [
            $row->kode,
            $row->tanggal_bayar->toDateString(),
            $row->tagihan->kode,
            $row->tagihan->murid->nama_lengkap,
            $row->tagihan->label(),
            $row->metode->label(),
            $row->bank_pengirim,
            $row->jumlah,
            $row->verifikator?->name,
        ];
    }
}
