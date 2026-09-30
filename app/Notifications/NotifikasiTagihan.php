<?php

namespace App\Notifications;

use App\Models\Tagihan;
use App\Support\Rupiah;

/**
 * Dasar notifikasi tagihan untuk wali murid. Label, nama anak, jumlah, dan jatuh tempo disalin saat
 * notifikasi dibuat (butuh relasi `jenisTagihan` dan `murid` pada tagihan).
 */
abstract class NotifikasiTagihan extends NotifikasiDatabase
{
    protected readonly int $tagihanId;

    protected readonly string $labelTagihan;

    protected readonly string $namaAnak;

    protected readonly string $total;

    protected readonly string $jatuhTempo;

    public function __construct(Tagihan $tagihan)
    {
        $this->tagihanId = $tagihan->id;
        $this->labelTagihan = $tagihan->label();
        $this->namaAnak = $tagihan->murid->nama_panggilan;
        $this->total = Rupiah::format($tagihan->total);
        $this->jatuhTempo = $tagihan->jatuh_tempo->translatedFormat('j F');
    }

    protected function url(object $notifiable): string
    {
        return $this->halaman($notifiable, "/tagihan/{$this->tagihanId}");
    }
}
