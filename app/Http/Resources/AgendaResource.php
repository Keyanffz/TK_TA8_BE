<?php

namespace App\Http\Resources;

use App\Models\Agenda;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agenda
 */
class AgendaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'judul' => $this->judul,
            'deskripsi' => $this->deskripsi,
            'tanggal_mulai' => $this->tanggal_mulai->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai->toDateString(),
            'jenis' => $this->jenis,
            'is_publik' => $this->is_publik,
            'created_at' => $this->created_at,
        ];
    }
}
