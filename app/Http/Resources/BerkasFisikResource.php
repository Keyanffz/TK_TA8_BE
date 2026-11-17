<?php

namespace App\Http\Resources;

use App\Models\Murid;
use App\Models\Pendaftaran;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Centang Kartu Keluarga dan Akta Kelahiran milik murid atau pendaftaran. Butuh relasi `kkDiterimaOleh` dan
 * `aktaDiterimaOleh`. Berkas yang belum dicentang bernilai null.
 *
 * @property Murid|Pendaftaran $resource
 */
class BerkasFisikResource extends JsonResource
{
    /**
     * @return array{kartu_keluarga: array{diterima_at: CarbonInterface, diterima_oleh: array{id: int, nama: string}|null}|null, akta_kelahiran: array{diterima_at: CarbonInterface, diterima_oleh: array{id: int, nama: string}|null}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'kartu_keluarga' => $this->centang($this->resource->kk_diterima_at, $this->resource->kkDiterimaOleh),
            'akta_kelahiran' => $this->centang($this->resource->akta_diterima_at, $this->resource->aktaDiterimaOleh),
        ];
    }

    /**
     * @return array{diterima_at: CarbonInterface, diterima_oleh: array{id: int, nama: string}|null}|null
     */
    private function centang(?CarbonInterface $waktu, ?User $oleh): ?array
    {
        return $waktu === null ? null : [
            'diterima_at' => $waktu,
            'diterima_oleh' => $oleh === null ? null : ['id' => $oleh->id, 'nama' => $oleh->name],
        ];
    }
}
