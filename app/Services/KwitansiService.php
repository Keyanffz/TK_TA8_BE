<?php

namespace App\Services;

use App\Enums\StatusPembayaran;
use App\Exceptions\BusinessRuleException;
use App\Models\Pembayaran;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DokumenPdf;
use Illuminate\Support\Facades\Storage;

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
            'sekolah' => $this->kopSekolah(),
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

    /**
     * @return array{nama: string, alamat: string, telepon: string, email: string, logo: string|null}
     */
    private function kopSekolah(): array
    {
        $logo = $this->pengaturan->nilai('profil.logo');
        $diskPublik = Storage::disk(MediaService::DISK_PUBLIK);

        return [
            'nama' => (string) $this->pengaturan->nilai('profil.nama_sekolah', ''),
            'alamat' => (string) $this->pengaturan->nilai('profil.alamat', ''),
            'telepon' => (string) $this->pengaturan->nilai('profil.telepon', ''),
            'email' => (string) $this->pengaturan->nilai('profil.email', ''),
            'logo' => is_string($logo) && $diskPublik->exists($logo) ? $diskPublik->path($logo) : null,
        ];
    }
}
