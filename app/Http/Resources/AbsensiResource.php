<?php

namespace App\Http\Resources;

use App\Models\Absensi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Butuh relasi `pengoreksi`. Foto diambil lewat `GET /absensi/{id}/foto`; `ada_foto` false untuk baris
 * `tidak_hadir` dan foto yang sudah melewati masa simpan.
 *
 * @mixin Absensi
 */
class AbsensiResource extends JsonResource
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
            'status' => $this->status,
            'waktu' => $this->waktu,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'akurasi_meter' => $this->akurasi_meter,
            'jarak_meter' => $this->jarak_meter,
            'ada_foto' => $this->foto_path !== null,
            'catatan_koreksi' => $this->catatan_koreksi,
            /** @var array{id: int, nama: string}|null */
            'dikoreksi_oleh' => $this->pengoreksi === null ? null : ['id' => $this->pengoreksi->id, 'nama' => $this->pengoreksi->name],
            'dikoreksi_at' => $this->dikoreksi_at,
        ];
    }
}
