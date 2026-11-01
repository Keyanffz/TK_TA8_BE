<?php

namespace App\Http\Resources;

use App\Models\Pengumuman;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengumuman di landing page: hanya yang `is_publik` dan sudah terbit, tanpa data penulis dan sasaran.
 *
 * @mixin Pengumuman
 */
class PengumumanPublikResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'judul' => $this->judul,
            'slug' => $this->slug,
            /** HTML yang sudah disanitasi. */
            'isi' => $this->isi,
            /**
             * Null kalau pengumuman tidak punya lampiran. `url` menuju `GET /public/pengumuman/{slug}/lampiran` dan tidak
             * kedaluwarsa, karena halaman publik di-cache lebih lama dari masa berlaku signed URL.
             */
            'lampiran' => $this->lampiran_path === null ? null : [
                'nama' => (string) $this->lampiran_nama,
                'jenis' => $this->jenisLampiran(),
                /** Ukuran file dalam byte. */
                'ukuran' => (int) $this->lampiran_ukuran,
                'url' => route('publik.pengumuman.lampiran', ['slug' => $this->slug]),
            ],
            'is_pinned' => $this->is_pinned,
            'published_at' => $this->published_at,
        ];
    }
}
