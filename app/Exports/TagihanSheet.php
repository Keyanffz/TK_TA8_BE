<?php

namespace App\Exports;

use App\Models\Tagihan;
use App\Services\LaporanKeuanganService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * @implements WithMapping<Tagihan>
 */
class TagihanSheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private readonly Carbon $dari, private readonly Carbon $sampai) {}

    /**
     * @return Builder<Tagihan>
     */
    public function query(): Builder
    {
        return app(LaporanKeuanganService::class)->tagihan($this->dari, $this->sampai)
            ->with(['jenisTagihan', 'murid.kelasAktif'])
            ->orderBy('jatuh_tempo')
            ->orderBy('kode');
    }

    public function title(): string
    {
        return 'Tagihan';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Kode', 'NIS', 'Nama Murid', 'Kelas', 'Tagihan', 'Jatuh Tempo', 'Nominal', 'Potongan', 'Total', 'Status', 'Lunas Pada'];
    }

    /**
     * @param  Tagihan  $row
     * @return list<string|int|null>
     */
    public function map($row): array
    {
        return [
            $row->kode,
            $row->murid->nis,
            $row->murid->nama_lengkap,
            $row->murid->kelasAktif->first()?->nama,
            $row->label(),
            $row->jatuh_tempo->toDateString(),
            $row->nominal,
            $row->potongan,
            $row->total,
            $row->status->label(),
            $row->lunas_at?->format('Y-m-d H:i'),
        ];
    }
}
