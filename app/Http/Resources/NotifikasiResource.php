<?php

namespace App\Http\Resources;

use App\Enums\JenisNotifikasi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Bentuk notifikasi A7. `jenis`, `judul`, `pesan`, dan `url` disimpan di kolom `data` oleh `NotifikasiDatabase`.
 *
 * @mixin DatabaseNotification
 */
class NotifikasiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{jenis: string, judul: string, pesan: string, url: string} $isi */
        $isi = $this->data;

        return [
            'id' => $this->id,
            'jenis' => JenisNotifikasi::from($isi['jenis']),
            'judul' => $isi['judul'],
            'pesan' => $isi['pesan'],
            /** Path halaman FE tujuan, misalnya `/dashboard/tagihan/12`. */
            'url' => $isi['url'],
            'dibaca_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
