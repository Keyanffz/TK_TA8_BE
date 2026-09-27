<?php

namespace App\Http\Resources;

use App\Models\Pembayaran;
use Illuminate\Http\Request;

/**
 * Pembayaran beserta tagihannya, untuk `GET /pembayaran` dan aksi pembayaran. Butuh relasi `tagihan` (dengan
 * `murid` dan `jenisTagihan`), `pembayar`, dan `verifikator`.
 *
 * @mixin Pembayaran
 */
class PembayaranResource extends RiwayatPembayaranResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'tagihan' => new TagihanResource($this->tagihan),
        ];
    }
}
