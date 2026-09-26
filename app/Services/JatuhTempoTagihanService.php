<?php

namespace App\Services;

use App\Enums\StatusTagihan;
use App\Models\Tagihan;
use App\Notifications\PengingatTagihanNotification;
use App\Notifications\TagihanTerlambatNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Tugas harian scheduler (B6.2): menandai tagihan terlambat dan mengirim pengingat H-N sebelum jatuh tempo.
 * Hanya tagihan `belum_bayar` yang diproses; tagihan yang bukti transfernya sedang diverifikasi dilewati.
 */
class JatuhTempoTagihanService
{
    private const UKURAN_CHUNK = 100;

    private const HARI_PENGINGAT_BAWAAN = 3;

    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * @return int jumlah tagihan yang ditandai (atau akan ditandai, saat simulasi)
     */
    public function tandaiTerlambat(Carbon $hariIni, bool $simulasi = false): int
    {
        $query = Tagihan::query()->where('status', StatusTagihan::BelumBayar)->whereDate('jatuh_tempo', '<', $hariIni->toDateString());

        if ($simulasi) {
            return $query->count();
        }

        $jumlah = 0;
        $this->denganPenerima($query)->chunkById(self::UKURAN_CHUNK, function (Collection $tagihan) use (&$jumlah): void {
            foreach ($tagihan as $satu) {
                $satu->update(['status' => StatusTagihan::Terlambat]);
                Notification::send($satu->murid->waliMurid->pluck('user'), new TagihanTerlambatNotification($satu));
                $jumlah++;
            }
        });

        return $jumlah;
    }

    /**
     * Pengingat dikirim tepat H-`keuangan.hari_pengingat`, jadi menjalankan command dua kali di hari yang
     * sama akan mengirim dua kali.
     *
     * @return int jumlah tagihan yang diingatkan (atau akan diingatkan, saat simulasi)
     */
    public function kirimPengingat(Carbon $hariIni, bool $simulasi = false): int
    {
        $sisaHari = (int) $this->pengaturan->nilai('keuangan.hari_pengingat', self::HARI_PENGINGAT_BAWAAN);
        $query = Tagihan::query()->where('status', StatusTagihan::BelumBayar)
            ->whereDate('jatuh_tempo', $hariIni->copy()->addDays($sisaHari)->toDateString());

        if ($simulasi) {
            return $query->count();
        }

        $jumlah = 0;
        $this->denganPenerima($query)->chunkById(self::UKURAN_CHUNK, function (Collection $tagihan) use (&$jumlah, $sisaHari): void {
            foreach ($tagihan as $satu) {
                Notification::send($satu->murid->waliMurid->pluck('user'), new PengingatTagihanNotification($satu, $sisaHari));
                $jumlah++;
            }
        });

        return $jumlah;
    }

    /**
     * @param  Builder<Tagihan>  $query
     * @return Builder<Tagihan>
     */
    private function denganPenerima(Builder $query): Builder
    {
        return $query->with(['jenisTagihan', 'murid.waliMurid.user']);
    }
}
