<?php

namespace App\Http\Resources;

use App\Models\JenisTagihan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JenisTagihan
 */
class JenisTagihanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tahun_ajaran' => $this->whenLoaded('tahunAjaran', fn () => ['id' => $this->tahunAjaran->id, 'nama' => $this->tahunAjaran->nama]),
            'nama' => $this->nama,
            'deskripsi' => $this->deskripsi,
            'nominal' => $this->nominal,
            'periode' => $this->periode,
            'tingkat' => $this->tingkat,
            'is_aktif' => $this->is_aktif,
            'created_at' => $this->created_at,
        ];
    }
}
