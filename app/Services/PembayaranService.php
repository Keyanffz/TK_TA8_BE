<?php

namespace App\Services;

use App\Enums\MetodeBayar;
use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Exceptions\BusinessRuleException;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\User;
use App\Notifications\NotifikasiTagihan;
use App\Notifications\PembayaranDiterimaNotification;
use App\Notifications\PembayaranDitolakNotification;
use App\Notifications\PembayaranMasukNotification;
use App\Support\NomorUrut;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Alur pembayaran (B6.3): tidak ada cicilan, jadi `jumlah` selalu sama dengan `total` tagihan. Satu tagihan
 * paling banyak punya satu pembayaran `menunggu` dan satu `diterima`; dijaga dengan mengunci baris tagihan.
 * Kode `PAY-YYYYMMDD-XXXXX` memakai tanggal pembayaran dicatat.
 */
class PembayaranService
{
    public const DIGIT_KODE = 5;

    private const FOLDER_BUKTI = 'bukti-bayar';

    public function __construct(private readonly MediaService $media) {}

    public static function awalanKode(Carbon $tanggal): string
    {
        return 'PAY-'.$tanggal->format('Ymd').'-';
    }

    /**
     * Wali mengunggah bukti transfer; tagihan menunggu verifikasi dan petugas keuangan diberi tahu.
     *
     * @param  array{tanggal_bayar: string, bank_pengirim: string, nama_pengirim: string}  $data
     *
     * @throws BusinessRuleException
     */
    public function unggahBukti(Tagihan $tagihan, array $data, UploadedFile $bukti, User $wali): Pembayaran
    {
        $buktiPath = $this->media->simpanGambar($bukti, MediaService::DISK_PRIVAT, self::FOLDER_BUKTI);

        try {
            $pembayaran = DB::transaction(function () use ($tagihan, $data, $buktiPath, $wali): Pembayaran {
                $tagihan = $this->kunciTagihanYangBisaDibayar($tagihan);
                $pembayaran = $this->catat($tagihan, [
                    ...$data,
                    'dibayar_oleh' => $wali->id,
                    'metode' => MetodeBayar::Transfer,
                    'bukti_path' => $buktiPath,
                    'status' => StatusPembayaran::Menunggu,
                ]);
                $tagihan->update(['status' => StatusTagihan::MenungguVerifikasi]);

                return $pembayaran;
            });
        } catch (Throwable $e) {
            $this->media->hapus($buktiPath, MediaService::DISK_PRIVAT);

            throw $e;
        }

        $pembayaran->load(['tagihan.jenisTagihan', 'tagihan.murid']);
        Notification::send(User::query()->petugasKeuanganAktif()->get(), new PembayaranMasukNotification($pembayaran));

        return $pembayaran;
    }

    /**
     * Pembayaran tunai dicatat petugas keuangan dan langsung diterima.
     *
     * @throws BusinessRuleException
     */
    public function catatTunai(Tagihan $tagihan, Carbon $tanggalBayar, User $petugas): Pembayaran
    {
        $pembayaran = DB::transaction(function () use ($tagihan, $tanggalBayar, $petugas): Pembayaran {
            $tagihan = $this->kunciTagihanYangBisaDibayar($tagihan);
            $pembayaran = $this->catat($tagihan, [
                'tanggal_bayar' => $tanggalBayar->toDateString(),
                'metode' => MetodeBayar::Tunai,
                'status' => StatusPembayaran::Diterima,
                'diverifikasi_oleh' => $petugas->id,
                'diverifikasi_at' => now(),
            ]);
            $tagihan->update(['status' => StatusTagihan::Lunas, 'lunas_at' => now()]);

            return $pembayaran;
        });

        $this->catatLog($pembayaran, $petugas, 'tunai_dicatat', "Mencatat pembayaran tunai {$pembayaran->kode}");
        $this->beriTahuWali($pembayaran, fn (Tagihan $tagihan) => new PembayaranDiterimaNotification($tagihan));

        return $pembayaran;
    }

    /**
     * @throws BusinessRuleException
     */
    public function terima(Pembayaran $pembayaran, User $petugas): Pembayaran
    {
        $pembayaran = DB::transaction(function () use ($pembayaran, $petugas): Pembayaran {
            [$pembayaran, $tagihan] = $this->kunciPembayaranMenunggu($pembayaran);

            $pembayaran->update([
                'status' => StatusPembayaran::Diterima,
                'diverifikasi_oleh' => $petugas->id,
                'diverifikasi_at' => now(),
            ]);
            $tagihan->update(['status' => StatusTagihan::Lunas, 'lunas_at' => now()]);

            return $pembayaran;
        });

        $this->catatLog($pembayaran, $petugas, 'diterima', "Menerima pembayaran {$pembayaran->kode}");
        $this->beriTahuWali($pembayaran, fn (Tagihan $tagihan) => new PembayaranDiterimaNotification($tagihan));

        return $pembayaran;
    }

    /**
     * Tagihan kembali `terlambat` kalau sudah lewat jatuh tempo, selain itu `belum_bayar`.
     *
     * @throws BusinessRuleException
     */
    public function tolak(Pembayaran $pembayaran, string $alasan, User $petugas): Pembayaran
    {
        $pembayaran = DB::transaction(function () use ($pembayaran, $alasan, $petugas): Pembayaran {
            [$pembayaran, $tagihan] = $this->kunciPembayaranMenunggu($pembayaran);

            $pembayaran->update([
                'status' => StatusPembayaran::Ditolak,
                'alasan_penolakan' => $alasan,
                'diverifikasi_oleh' => $petugas->id,
                'diverifikasi_at' => now(),
            ]);
            $tagihan->update([
                'status' => $tagihan->jatuh_tempo->lt(today()) ? StatusTagihan::Terlambat : StatusTagihan::BelumBayar,
            ]);

            return $pembayaran;
        });

        $this->catatLog($pembayaran, $petugas, 'ditolak', "Menolak pembayaran {$pembayaran->kode}", ['alasan' => $alasan]);
        $this->beriTahuWali($pembayaran, fn (Tagihan $tagihan) => new PembayaranDitolakNotification($tagihan, $alasan));

        return $pembayaran;
    }

    /**
     * @throws BusinessRuleException
     */
    private function kunciTagihanYangBisaDibayar(Tagihan $tagihan): Tagihan
    {
        $tagihan = Tagihan::query()->lockForUpdate()->findOrFail($tagihan->id);

        $alasanTolak = match (true) {
            $tagihan->status === StatusTagihan::Lunas => 'Tagihan ini sudah lunas.',
            $tagihan->status === StatusTagihan::Dibatalkan => 'Tagihan ini sudah dibatalkan sehingga tidak perlu dibayar.',
            $tagihan->pembayaran()->where('status', StatusPembayaran::Menunggu)->exists() => 'Masih ada bukti transfer untuk tagihan ini yang menunggu verifikasi. Tunggu hasil verifikasinya terlebih dahulu.',
            default => null,
        };

        if ($alasanTolak !== null) {
            throw new BusinessRuleException($alasanTolak);
        }

        return $tagihan;
    }

    /**
     * @return array{0: Pembayaran, 1: Tagihan}
     *
     * @throws BusinessRuleException
     */
    private function kunciPembayaranMenunggu(Pembayaran $pembayaran): array
    {
        $tagihan = Tagihan::query()->lockForUpdate()->findOrFail($pembayaran->tagihan_id);
        $pembayaran = Pembayaran::query()->lockForUpdate()->findOrFail($pembayaran->id);

        if ($pembayaran->status !== StatusPembayaran::Menunggu) {
            throw new BusinessRuleException("Pembayaran {$pembayaran->kode} sudah diverifikasi (status: {$pembayaran->status->label()}).");
        }

        return [$pembayaran, $tagihan];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function catat(Tagihan $tagihan, array $data): Pembayaran
    {
        $awalan = self::awalanKode(now());

        return Pembayaran::query()->create([
            ...$data,
            'kode' => NomorUrut::format($awalan, NomorUrut::berikutnya(Pembayaran::query(), 'kode', $awalan, self::DIGIT_KODE), self::DIGIT_KODE),
            'tagihan_id' => $tagihan->id,
            'jumlah' => $tagihan->total,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properti
     */
    private function catatLog(Pembayaran $pembayaran, User $petugas, string $event, string $pesan, array $properti = []): void
    {
        activity('pembayaran')->causedBy($petugas)->performedOn($pembayaran)->event($event)
            ->withProperties(['tagihan_id' => $pembayaran->tagihan_id, 'jumlah' => $pembayaran->jumlah, ...$properti])
            ->log($pesan);
    }

    /**
     * @param  callable(Tagihan): NotifikasiTagihan  $notifikasi
     */
    private function beriTahuWali(Pembayaran $pembayaran, callable $notifikasi): void
    {
        $tagihan = Tagihan::query()->with(['jenisTagihan', 'murid.waliMurid.user'])->findOrFail($pembayaran->tagihan_id);

        Notification::send($tagihan->murid->waliMurid->pluck('user'), $notifikasi($tagihan));
    }
}
