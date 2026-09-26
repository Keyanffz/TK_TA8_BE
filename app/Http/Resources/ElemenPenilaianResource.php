<?php

namespace App\Http\Resources;

use App\Models\ElemenPenilaian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ElemenPenilaian
 */
class ElemenPenilaianResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'nama' => $this->nama,
            'deskripsi' => $this->deskripsi,
            'urutan' => $this->urutan,
            'is_aktif' => $this->is_aktif,
        ];
    }
}
