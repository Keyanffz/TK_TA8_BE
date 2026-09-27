<?php

namespace App\Http\Resources;

use App\Models\WaliMurid;
use Illuminate\Http\Request;

/**
 * Detail wali murid: bentuk daftar ditambah anak yang tertaut (butuh relasi `murid.kelasAktif`).
 *
 * @mixin WaliMurid
 */
class WaliMuridDetailResource extends WaliMuridResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'anak' => AnakWaliResource::collection($this->murid),
        ];
    }
}
