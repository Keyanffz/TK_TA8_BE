<?php

namespace App\Http\Resources;

use App\Models\GaleriAlbum;
use App\Models\GaleriFoto;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Galeri ada di disk public (B5), jadi semua URL-nya URL biasa. `cover_url` memakai foto pertama kalau album
 * tidak punya sampul sendiri. `foto` hanya ada di detail album.
 *
 * @mixin GaleriAlbum
 */
class GaleriAlbumResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);
        $sampul = $this->cover_path ?? ($this->relationLoaded('fotoPertama') ? $this->fotoPertama?->path : null);

        return [
            'id' => $this->id,
            'judul' => $this->judul,
            'slug' => $this->slug,
            'deskripsi' => $this->deskripsi,
            'tanggal' => $this->tanggal->toDateString(),
            'is_publik' => $this->is_publik,
            'cover_url' => $media->urlPublik($sampul),
            'jumlah_foto' => $this->whenCounted('foto'),
            'foto' => $this->whenLoaded('foto', fn () => $this->foto->map(fn (GaleriFoto $foto): array => [
                'id' => $foto->id,
                'url' => $media->urlPublik($foto->path),
                'caption' => $foto->caption,
                'urutan' => $foto->urutan,
            ])->values()->all()),
            'created_at' => $this->created_at,
        ];
    }
}
