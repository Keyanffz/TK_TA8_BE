<?php

namespace App\Http\Resources;

use App\Models\AksesAbsenSusulan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Butuh relasi `pemberi`.
 *
 * @mixin AksesAbsenSusulan
 */
class AksesAbsenSusulanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'tanggal' => $this->tanggal->toDateString(),
            'jenis' => $this->jenis,
            'catatan' => $this->catatan,
            /** Akhir hari akses diberikan (23:59:59 WIB). */
            'berlaku_sampai' => $this->berlaku_sampai,
            /** Null kalau belum dipakai. */
            'dipakai_at' => $this->dipakai_at,
            /** @var array{id: int, nama: string} */
            'diberikan_oleh' => ['id' => $this->pemberi->id, 'nama' => $this->pemberi->name],
        ];
    }
}
