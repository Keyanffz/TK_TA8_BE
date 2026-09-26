<?php

namespace App\Http\Resources;

use App\Models\TahunAjaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TahunAjaran
 */
class TahunAjaranResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'tanggal_mulai' => $this->tanggal_mulai->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai->toDateString(),
            'semester_aktif' => $this->semester_aktif,
            'is_aktif' => $this->is_aktif,
            'created_at' => $this->created_at,
        ];
    }
}
