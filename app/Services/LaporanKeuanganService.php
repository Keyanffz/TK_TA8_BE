<?php

namespace App\Services;

use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\WaliMurid;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Laporan keuangan untuk petugas keuangan. Tagihan dikelompokkan menurut bulan jatuh temponya (tagihan
 * dibatalkan tidak dihitung); pemasukan dihitung dari pembayaran `diterima` menurut tanggal bayar.
 * Jumlah data per sekolah kecil (puluhan murid), jadi pengelompokan dilakukan di PHP supaya sama di
 * MySQL, MariaDB, dan SQLite.
 */
class LaporanKeuanganService
{
    /**
     * @return array{
     *     dari: string,
     *     sampai: string,
     *     ringkasan: array{jumlah_tagihan: int, total_tagihan: int, terbayar: int, belum_terbayar: int, persen_lunas: float, pemasukan: int},
     *     per_jenis: list<array{jenis_tagihan: array{id: int, nama: string}, jumlah_tagihan: int, total_tagihan: int, terbayar: int, belum_terbayar: int, persen_lunas: float}>,
     *     per_bulan: list<array{bulan: string, jumlah_tagihan: int, total_tagihan: int, terbayar: int, belum_terbayar: int, persen_lunas: float, pemasukan: int}>
     * }
     */
    public function ringkasan(Carbon $dari, Carbon $sampai, ?int $kelasId): array
    {
        $tagihan = $this->tagihan($dari, $sampai, $kelasId)->with('jenisTagihan')->get();
        $pemasukan = $this->pemasukan($dari, $sampai, $kelasId)->get(['id', 'tanggal_bayar', 'jumlah']);

        $perJenis = $tagihan->groupBy('jenis_tagihan_id')->map(fn (Collection $kelompok): array => [
            'jenis_tagihan' => ['id' => $kelompok->first()->jenisTagihan->id, 'nama' => $kelompok->first()->jenisTagihan->nama],
            ...$this->hitung($kelompok),
        ])->sortBy('jenis_tagihan.nama', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        $bulan = CarbonPeriod::create($dari->copy()->startOfMonth(), '1 month', $sampai->copy()->startOfMonth());
        $perBulan = collect(iterator_to_array($bulan, false))
            ->map(function (Carbon $bulan) use ($tagihan, $pemasukan): array {
                $kunci = $bulan->format('Y-m');

                return [
                    'bulan' => $kunci,
                    ...$this->hitung($tagihan->filter(fn (Tagihan $t): bool => $t->jatuh_tempo->format('Y-m') === $kunci)),
                    'pemasukan' => (int) $pemasukan->filter(fn (Pembayaran $p): bool => $p->tanggal_bayar->format('Y-m') === $kunci)->sum('jumlah'),
                ];
            })->values()->all();

        return [
            'dari' => $dari->toDateString(),
            'sampai' => $sampai->toDateString(),
            'ringkasan' => [...$this->hitung($tagihan), 'pemasukan' => (int) $pemasukan->sum('jumlah')],
            'per_jenis' => $perJenis,
            'per_bulan' => $perBulan,
        ];
    }

    /**
     * Murid yang punya tagihan berstatus `terlambat`, urut total tunggakan terbesar, beserta kontak utama walinya.
     *
     * @return array{
     *     total_tunggakan: int,
     *     jumlah_murid: int,
     *     murid: list<array{
     *         id: int, nis: string, nama_lengkap: string, kelas: array{id: int, nama: string}|null,
     *         kontak_wali: array{nama: string, no_hp: string|null}|null, jumlah_tagihan: int, total: int,
     *         tagihan: list<array{id: int, kode: string, nama: string, jatuh_tempo: string, total: int}>
     *     }>
     * }
     */
    public function tunggakan(?int $kelasId): array
    {
        $tagihan = Tagihan::query()
            ->where('status', StatusTagihan::Terlambat)
            ->when($kelasId !== null, fn (Builder $query) => $query->whereHas('murid.kelas', fn (Builder $kelas) => $kelas->whereKey($kelasId)))
            ->with(['jenisTagihan', 'murid.kelasAktif', 'murid.waliMurid.user'])
            ->orderBy('jatuh_tempo')
            ->get();

        $murid = $tagihan->groupBy('murid_id')->map(function (Collection $milikMurid): array {
            /** @var Murid $murid */
            $murid = $milikMurid->first()->murid;
            $kelas = $murid->kelasAktif->first();
            $kontak = $murid->waliMurid->first(fn (WaliMurid $wali): bool => $this->kontakUtama($wali)) ?? $murid->waliMurid->first();

            return [
                'id' => $murid->id,
                'nis' => $murid->nis,
                'nama_lengkap' => $murid->nama_lengkap,
                'kelas' => $kelas === null ? null : ['id' => $kelas->id, 'nama' => $kelas->nama],
                'kontak_wali' => $kontak === null ? null : ['nama' => $kontak->user->name, 'no_hp' => $kontak->user->no_hp],
                'jumlah_tagihan' => $milikMurid->count(),
                'total' => (int) $milikMurid->sum('total'),
                'tagihan' => $milikMurid->map(fn (Tagihan $satu): array => [
                    'id' => $satu->id,
                    'kode' => $satu->kode,
                    'nama' => $satu->label(),
                    'jatuh_tempo' => $satu->jatuh_tempo->toDateString(),
                    'total' => $satu->total,
                ])->values()->all(),
            ];
        })->sortByDesc('total')->values();

        return [
            'total_tunggakan' => (int) $murid->sum('total'),
            'jumlah_murid' => $murid->count(),
            'murid' => $murid->all(),
        ];
    }

    /**
     * @return Builder<Tagihan>
     */
    public function tagihan(Carbon $dari, Carbon $sampai, ?int $kelasId = null): Builder
    {
        return Tagihan::query()
            ->where('status', '!=', StatusTagihan::Dibatalkan)
            ->whereDate('jatuh_tempo', '>=', $dari->toDateString())
            ->whereDate('jatuh_tempo', '<=', $sampai->toDateString())
            ->when($kelasId !== null, fn (Builder $query) => $query->whereHas('murid.kelas', fn (Builder $kelas) => $kelas->whereKey($kelasId)));
    }

    /**
     * @return Builder<Pembayaran>
     */
    public function pemasukan(Carbon $dari, Carbon $sampai, ?int $kelasId = null): Builder
    {
        return Pembayaran::query()
            ->where('status', StatusPembayaran::Diterima)
            ->whereDate('tanggal_bayar', '>=', $dari->toDateString())
            ->whereDate('tanggal_bayar', '<=', $sampai->toDateString())
            ->when($kelasId !== null, fn (Builder $query) => $query->whereHas('tagihan.murid.kelas', fn (Builder $kelas) => $kelas->whereKey($kelasId)));
    }

    /**
     * @param  SupportCollection<int, Tagihan>  $tagihan
     * @return array{jumlah_tagihan: int, total_tagihan: int, terbayar: int, belum_terbayar: int, persen_lunas: float}
     */
    private function hitung(SupportCollection $tagihan): array
    {
        $total = (int) $tagihan->sum('total');
        $terbayar = (int) $tagihan->where('status', StatusTagihan::Lunas)->sum('total');

        return [
            'jumlah_tagihan' => $tagihan->count(),
            'total_tagihan' => $total,
            'terbayar' => $terbayar,
            'belum_terbayar' => $total - $terbayar,
            'persen_lunas' => $total === 0 ? 0.0 : round($terbayar / $total * 100, 1),
        ];
    }

    private function kontakUtama(WaliMurid $wali): bool
    {
        $tautan = $wali->getRelation('pivot');

        return $tautan instanceof MuridWali && $tautan->is_kontak_utama;
    }
}
