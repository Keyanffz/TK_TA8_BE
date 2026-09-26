<?php

namespace App\Services;

use App\Models\Pengaturan;

/**
 * Membaca nilai tabel `pengaturan` per kunci (A4 "Kunci pengaturan"). Penyimpanan, validasi per kunci,
 * dan cache dibuat bersama `PUT /pengaturan` di Fase 7.
 */
class PengaturanService
{
    public function nilai(string $kunci, mixed $bawaan = null): mixed
    {
        $pengaturan = Pengaturan::query()->where('kunci', $kunci)->first();

        return $pengaturan->nilai ?? $bawaan;
    }

    /**
     * @return list<array{bank: string, nomor: string, atas_nama: string}>
     */
    public function rekeningSekolah(): array
    {
        $rekening = $this->nilai('keuangan.rekening', []);

        return is_array($rekening) ? array_values($rekening) : [];
    }
}
