<?php

namespace App\Http\Resources;

use App\Models\WaliMurid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk wali murid di daftar. Butuh relasi `user` dan `withCount('murid')`. Daftar anak ada di
 * `WaliMuridDetailResource`.
 *
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
            'user' => new AkunResource($this->user),
            'nik' => $this->nik,
            'pekerjaan' => $this->pekerjaan,
            'alamat' => $this->alamat,
            'profil_lengkap' => $this->profil_lengkap,
            'jumlah_anak' => (int) $this->murid_count,
            'created_at' => $this->created_at,
        ];
    }
}
