<?php

namespace App\Http\Resources;

use App\Models\KegiatanFoto;
use App\Models\KegiatanKelas;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kegiatan hanya dikirim ke pengguna yang lolos `KegiatanKelas::visibleTo`, jadi `foto[].url` (signed URL
 * file private) aman dibuat di sini.
 *
 * @mixin KegiatanKelas
 */
class KegiatanKelasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);

        return [
            'id' => $this->id,
            'kelas' => ['id' => $this->kelas->id, 'nama' => $this->kelas->nama],
            'guru' => ['id' => $this->guru->id, 'nama' => $this->guru->user->name],
            'tanggal' => $this->tanggal->toDateString(),
            'tema' => $this->tema,
            'judul' => $this->judul,
            'deskripsi' => $this->deskripsi,
            'foto' => $this->foto->map(fn (KegiatanFoto $foto): array => [
                'id' => $foto->id,
                'url' => $media->urlPrivat($foto->path),
                'caption' => $foto->caption,
                'urutan' => $foto->urutan,
            ])->values()->all(),
            'created_at' => $this->created_at,
        ];
    }
}
