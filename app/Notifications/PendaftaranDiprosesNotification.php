<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Enums\StatusPendaftaran;
use App\Models\Pendaftaran;
use LogicException;

/**
 * Dikirim ke wali pendaftar setiap kali Kepala Sekolah memverifikasi, menerima, atau menolak pendaftaran.
 * Untuk pendaftaran yang diterima butuh relasi `murid.kelas`.
 */
class PendaftaranDiprosesNotification extends NotifikasiDatabase
{
    private readonly int $pendaftaranId;

    private readonly string $judulNotifikasi;

    private readonly string $pesanNotifikasi;

    public function __construct(Pendaftaran $pendaftaran)
    {
        $this->pendaftaranId = $pendaftaran->id;
        $anak = "{$pendaftaran->nama_panggilan} ({$pendaftaran->kode})";
        $kelas = $pendaftaran->murid?->kelas->first()?->nama;

        [$this->judulNotifikasi, $this->pesanNotifikasi] = match ($pendaftaran->status) {
            StatusPendaftaran::Diverifikasi => [
                'Dokumen PPDB sudah diverifikasi',
                "Dokumen pendaftaran {$anak} sudah diverifikasi. Tunggu keputusan akhir dari sekolah.",
            ],
            StatusPendaftaran::Diterima => [
                'Pendaftaran PPDB diterima',
                $kelas === null
                    ? "{$anak} diterima dan sudah tertaut ke akun Anda. Kelasnya akan diinformasikan sekolah."
                    : "{$anak} diterima di {$kelas} dan sudah tertaut ke akun Anda.",
            ],
            StatusPendaftaran::Ditolak => [
                'Pendaftaran PPDB ditolak',
                "Pendaftaran {$anak} ditolak: ".rtrim((string) $pendaftaran->catatan, '. ').'.',
            ],
            StatusPendaftaran::Diajukan => throw new LogicException('Pendaftaran yang baru diajukan belum diproses.'),
        };
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::PendaftaranDiproses;
    }

    protected function judul(): string
    {
        return $this->judulNotifikasi;
    }

    protected function pesan(object $notifiable): string
    {
        return $this->pesanNotifikasi;
    }

    protected function url(object $notifiable): string
    {
        return "/dashboard/ppdb/{$this->pendaftaranId}";
    }
}
