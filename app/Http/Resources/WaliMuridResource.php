<?php

namespace App\Http\Resources;

use App\Models\WaliMurid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WaliMurid
 */
class WaliMuridResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new AkunResource($this->whenLoaded('user')),
            'nik' => $this->nik,
            'pekerjaan' => $this->pekerjaan,
            'alamat' => $this->alamat,
            'profil_lengkap' => $this->profil_lengkap,
            'jumlah_anak' => $this->whenCounted('murid'),
            'anak' => AnakWaliResource::collection($this->whenLoaded('murid')),
            'created_at' => $this->created_at,
        ];
    }
}
