<?php

namespace App\Services;

use App\Enums\StatusPembayaran;
use App\Exceptions\BusinessRuleException;
use App\Models\Pembayaran;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DokumenPdf;

/**
 * Kwitansi PDF untuk satu pembayaran yang diterima. Karena tagihan boleh dicicil, kwitansi memuat total
 * tagihan, yang sudah dibayar sebelum pembayaran ini, dan sisa sesudahnya. Kop diambil dari pengaturan profil
 * sekolah.
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
        return Pdf::loadView('pdf.kwitansi', $this->isi($pembayaran))
            ->setPaper('a5', 'landscape')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * Data untuk template kwitansi. "Sudah dibayar sebelumnya" mengikuti urutan pencatatan (id), bukan tanggal
     * bayar yang boleh diisi mundur.
     *
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException
     */
    public function isi(Pembayaran $pembayaran): array
    {
        if ($pembayaran->status !== StatusPembayaran::Diterima) {
            throw new BusinessRuleException('Kwitansi hanya tersedia untuk pembayaran yang sudah diterima.');
        }

        $pembayaran->loadMissing(['tagihan.jenisTagihan', 'tagihan.murid.kelasAktif', 'verifikator']);
        $tagihan = $pembayaran->tagihan;
        $dibayarSebelumnya = (int) $tagihan->pembayaran()->where('status', StatusPembayaran::Diterima)->where('id', '<', $pembayaran->id)->sum('jumlah');

        return [
            'sekolah' => $this->pengaturan->kopSekolah(),
            'pembayaran' => $pembayaran,
            'tagihan' => $tagihan,
            'dibayarSebelumnya' => $dibayarSebelumnya,
            'sisa' => max(0, $tagihan->total - $dibayarSebelumnya - $pembayaran->jumlah),
            'murid' => $tagihan->murid,
            'kelas' => $tagihan->murid->kelasAktif->first(),
        ];
    }

    public function namaFile(Pembayaran $pembayaran): string
    {
        return "kwitansi-{$pembayaran->kode}.pdf";
    }
}
