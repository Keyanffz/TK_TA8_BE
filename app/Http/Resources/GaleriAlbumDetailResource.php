<?php

namespace App\Http\Resources;

use App\Models\GaleriAlbum;
use App\Models\GaleriFoto;
use App\Services\MediaService;
use Illuminate\Http\Request;

/**
 * Detail album: bentuk daftar ditambah semua foto (butuh relasi `foto`).
 *
 * @mixin GaleriAlbum
 */
class GaleriAlbumDetailResource extends GaleriAlbumResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);

        return [
            ...parent::toArray($request),
            'foto' => $this->foto->map(fn (GaleriFoto $foto): array => [
                'id' => $foto->id,
                'url' => $media->urlPublik($foto->path),
                'caption' => $foto->caption,
                'urutan' => $foto->urutan,
            ])->values()->all(),
        ];
    }
}
