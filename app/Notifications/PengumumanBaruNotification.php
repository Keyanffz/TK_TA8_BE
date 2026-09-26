<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Models\Pengumuman;
use Illuminate\Support\Str;

class PengumumanBaruNotification extends NotifikasiDatabase
{
    private const PANJANG_CUPLIKAN = 140;

    private readonly int $pengumumanId;

    private readonly string $judulPengumuman;

    private readonly string $cuplikan;

    public function __construct(Pengumuman $pengumuman)
    {
        $this->pengumumanId = $pengumuman->id;
        $this->judulPengumuman = $pengumuman->judul;
        $teks = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $pengumuman->isi)), ENT_QUOTES | ENT_HTML5);
        $this->cuplikan = Str::limit(Str::squish($teks), self::PANJANG_CUPLIKAN);
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::PengumumanBaru;
    }

    protected function judul(): string
    {
        return $this->judulPengumuman;
    }

    protected function pesan(object $notifiable): string
    {
        return $this->cuplikan;
    }

    protected function url(object $notifiable): string
    {
        return "/dashboard/pengumuman/{$this->pengumumanId}";
    }
}
