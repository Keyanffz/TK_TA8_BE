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
        return [
            'id' => $this->id,
            /** @var JenisNotifikasi */
            'jenis' => JenisNotifikasi::from((string) $this->data['jenis']),
            'judul' => (string) $this->data['judul'],
            'pesan' => (string) $this->data['pesan'],
            /** Path halaman FE tujuan, misalnya `/dashboard/tagihan/12`. */
            'url' => (string) $this->data['url'],
            'dibaca_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
