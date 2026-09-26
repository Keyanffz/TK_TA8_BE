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
            'is_pinned' => $this->is_pinned,
            'published_at' => $this->published_at,
        ];
    }
}
