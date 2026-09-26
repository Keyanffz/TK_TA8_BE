<?php

namespace App\Services;

use App\Enums\StatusPembayaran;
use App\Exceptions\BusinessRuleException;
use App\Models\Pembayaran;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DokumenPdf;

/**
 * Kwitansi PDF untuk pembayaran yang sudah diterima. Kop diambil dari pengaturan profil sekolah.
 */
class KwitansiService
{
    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * Font disematkan sebagian (subsetting) supaya ukuran kwitansi puluhan KB, bukan hampir 1 MB.
     *
     * @throws BusinessRuleException
     */
    public function buat(Pembayaran $pembayaran): DokumenPdf
    {
        if ($pembayaran->status !== StatusPembayaran::Diterima) {
            throw new BusinessRuleException('Kwitansi hanya tersedia untuk pembayaran yang sudah diterima.');
        }

        $pembayaran->loadMissing(['tagihan.jenisTagihan', 'tagihan.murid.kelasAktif', 'verifikator', 'pembayar']);

        return Pdf::loadView('pdf.kwitansi', [
            'sekolah' => $this->pengaturan->kopSekolah(),
            'pembayaran' => $pembayaran,
            'tagihan' => $pembayaran->tagihan,
            'murid' => $pembayaran->tagihan->murid,
            'kelas' => $pembayaran->tagihan->murid->kelasAktif->first(),
        ])
            ->setPaper('a5', 'landscape')
            ->setOption('isFontSubsettingEnabled', true);
    }

    public function namaFile(Pembayaran $pembayaran): string
    {
        return "kwitansi-{$pembayaran->kode}.pdf";
    }
}
