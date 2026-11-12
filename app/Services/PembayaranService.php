<?php

namespace App\Services;

use App\Enums\MetodeBayar;
use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Exceptions\BusinessRuleException;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\User;
use App\Notifications\PembayaranDiterimaNotification;
use App\Support\NomorUrut;
use App\Support\Rupiah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Pembayaran tunai di sekolah (B6.3). Petugas mencatat jumlah yang diterima dan pembayaran langsung `diterima`.
 * Tagihan boleh dicicil: jumlah tiap pembayaran 1 sampai sisa, dan tagihan lunas saat sisanya habis. Baris
 * tagihan dikunci selama mencatat atau membatalkan, supaya dua pencatatan bersamaan tidak melebihi sisa.
 * Kode `PAY-YYYYMMDD-XXXXX` memakai tanggal pembayaran dicatat.
 */
class PembayaranService
{
    public const DIGIT_KODE = 5;

    public static function awalanKode(Carbon $tanggal): string
    {
        return 'PAY-'.$tanggal->format('Ymd').'-';
    }

    /**
     * `dibayar_oleh` kosong karena pembayarnya bukan pengguna aplikasi; petugas pencatat disimpan di
     * `diverifikasi_oleh`.
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function catat(Tagihan $tagihan, int $jumlah, string $tanggalBayar, User $petugas): Pembayaran
    {
        [$pembayaran, $sisa] = DB::transaction(function () use ($tagihan, $jumlah, $tanggalBayar, $petugas): array {
            $tagihan = Tagihan::query()->lockForUpdate()->findOrFail($tagihan->id);

            $alasanTolak = match ($tagihan->status) {
                StatusTagihan::Lunas => 'Tagihan ini sudah lunas.',
                StatusTagihan::Dibatalkan => 'Tagihan ini sudah dibatalkan sehingga tidak perlu dibayar.',
                default => null,
            };
            if ($alasanTolak !== null) {
                throw new BusinessRuleException($alasanTolak);
            }

            $sisa = $tagihan->sisa();
            if ($jumlah > $sisa) {
                throw ValidationException::withMessages(['jumlah' => 'Jumlah melebihi sisa tagihan ('.Rupiah::format($sisa).').']);
            }

            $awalan = self::awalanKode(now());
            $pembayaran = Pembayaran::query()->create([
                'kode' => NomorUrut::format($awalan, NomorUrut::berikutnya(Pembayaran::query(), 'kode', $awalan, self::DIGIT_KODE), self::DIGIT_KODE),
                'tagihan_id' => $tagihan->id,
                'metode' => MetodeBayar::Tunai,
                'jumlah' => $jumlah,
                'tanggal_bayar' => $tanggalBayar,
                'status' => StatusPembayaran::Diterima,
                'diverifikasi_oleh' => $petugas->id,
                'diverifikasi_at' => now(),
            ]);

            if ($jumlah === $sisa) {
                $tagihan->update(['status' => StatusTagihan::Lunas, 'lunas_at' => now()]);
            }

            return [$pembayaran, $sisa - $jumlah];
        });

        $this->catatLog($pembayaran, $petugas, 'dicatat', "Mencatat pembayaran tunai {$pembayaran->kode}", ['sisa' => $sisa]);

        $tagihan = Tagihan::query()->with(['jenisTagihan', 'murid.waliMurid.user'])->findOrFail($pembayaran->tagihan_id);
        Notification::send($tagihan->murid->waliMurid->pluck('user'), new PembayaranDiterimaNotification($tagihan, $jumlah, $sisa));

        return $pembayaran;
    }

    /**
     * Membatalkan pembayaran yang salah catat. Sisa tagihan dihitung ulang: tagihan yang tadinya lunas kembali
     * `terlambat` kalau sudah lewat jatuh tempo, selain itu `belum_bayar`. Wali tidak diberi notifikasi.
     *
     * @throws BusinessRuleException
     */
    public function batalkan(Pembayaran $pembayaran, string $alasan, User $kepalaSekolah): Pembayaran
    {
        $pembayaran = DB::transaction(function () use ($pembayaran, $alasan, $kepalaSekolah): Pembayaran {
            $tagihan = Tagihan::query()->lockForUpdate()->findOrFail($pembayaran->tagihan_id);
            $pembayaran = Pembayaran::query()->lockForUpdate()->findOrFail($pembayaran->id);

            if ($pembayaran->status !== StatusPembayaran::Diterima) {
                throw new BusinessRuleException("Pembayaran {$pembayaran->kode} berstatus {$pembayaran->status->label()}. Hanya pembayaran yang diterima yang bisa dibatalkan.");
            }

            $pembayaran->update([
                'status' => StatusPembayaran::Dibatalkan,
                'alasan_pembatalan' => $alasan,
                'dibatalkan_oleh' => $kepalaSekolah->id,
                'dibatalkan_at' => now(),
            ]);

            if ($tagihan->status === StatusTagihan::Lunas && $tagihan->sisa() > 0) {
                $tagihan->update([
                    'status' => $tagihan->jatuh_tempo->lt(today()) ? StatusTagihan::Terlambat : StatusTagihan::BelumBayar,
                    'lunas_at' => null,
                ]);
            }

            return $pembayaran;
        });

        $this->catatLog($pembayaran, $kepalaSekolah, 'dibatalkan', "Membatalkan pembayaran {$pembayaran->kode}", ['alasan' => $alasan]);

        return $pembayaran;
    }

    /**
     * @param  array<string, mixed>  $properti
     */
    private function catatLog(Pembayaran $pembayaran, User $pelaku, string $event, string $pesan, array $properti): void
    {
        activity('pembayaran')->causedBy($pelaku)->performedOn($pembayaran)->event($event)
            ->withProperties(['tagihan_id' => $pembayaran->tagihan_id, 'jumlah' => $pembayaran->jumlah, ...$properti])
            ->log($pesan);
    }
}
