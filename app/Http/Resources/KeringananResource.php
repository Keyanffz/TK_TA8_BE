<?php

namespace App\Http\Resources;

use App\Models\Keringanan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Keringanan
 */
class KeringananResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'murid' => $this->whenLoaded('murid', fn () => [
                'id' => $this->murid->id,
                'nis' => $this->murid->nis,
                'nama_lengkap' => $this->murid->nama_lengkap,
            ]),
            'jenis_tagihan' => $this->whenLoaded('jenisTagihan', fn () => [
                'id' => $this->jenisTagihan->id,
                'nama' => $this->jenisTagihan->nama,
                'nominal' => $this->jenisTagihan->nominal,
                'periode' => $this->jenisTagihan->periode,
            ]),
            'tipe' => $this->tipe,
            'nilai' => $this->nilai,
            'alasan' => $this->alasan,
            'berlaku_mulai' => $this->berlaku_mulai->toDateString(),
            'berlaku_sampai' => $this->berlaku_sampai?->toDateString(),
            'dibuat_oleh' => $this->whenLoaded('pembuat', fn () => $this->pembuat === null ? null : ['id' => $this->pembuat->id, 'nama' => $this->pembuat->name]),
            'created_at' => $this->created_at,
        ];
    }
}
