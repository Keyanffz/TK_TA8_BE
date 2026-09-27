<?php

namespace App\Http\Resources;

use App\Models\Tagihan;
use App\Services\PengaturanService;
use Illuminate\Http\Request;

/**
 * Detail tagihan (`GET /tagihan/{id}`): bentuk daftar ditambah riwayat pembayaran (butuh relasi `pembayaran`
 * beserta `pembayar` dan `verifikator`) dan rekening sekolah untuk transfer.
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
            /** @var list<array{bank: string, nomor: string, atas_nama: string}> */
            'rekening' => app(PengaturanService::class)->rekeningSekolah(),
        ];
    }
}
