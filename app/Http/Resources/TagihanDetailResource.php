<?php

namespace App\Http\Resources;

use App\Models\Tagihan;
use Illuminate\Http\Request;

/**
 * Detail tagihan (`GET /tagihan/{id}`): bentuk daftar ditambah riwayat pembayaran (butuh relasi `pembayaran`
 * beserta `pembayar`, `verifikator`, dan `pembatal`).
 *
 * @mixin Tagihan
 */
class TagihanDetailResource extends TagihanResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'pembayaran' => RiwayatPembayaranResource::collection($this->pembayaran),
        ];
    }
}
