<?php

namespace App\Services;

use App\Models\Pengaturan;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Kop dokumen PDF (kwitansi, rapor) dari pengaturan profil sekolah. `logo` berupa path file lokal supaya
     * bisa dibaca dompdf tanpa akses jaringan; null kalau belum diunggah.
     *
     * @return array{nama: string, alamat: string, telepon: string, email: string, logo: string|null}
     */
    public function kopSekolah(): array
    {
        $logo = $this->nilai('profil.logo');
        $diskPublik = Storage::disk(MediaService::DISK_PUBLIK);

        return [
            'nama' => (string) $this->nilai('profil.nama_sekolah', ''),
            'alamat' => (string) $this->nilai('profil.alamat', ''),
            'telepon' => (string) $this->nilai('profil.telepon', ''),
            'email' => (string) $this->nilai('profil.email', ''),
            'logo' => is_string($logo) && $diskPublik->exists($logo) ? $diskPublik->path($logo) : null,
        ];
    }
}
