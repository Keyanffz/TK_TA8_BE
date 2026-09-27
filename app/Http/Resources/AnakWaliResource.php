<?php

namespace App\Http\Resources;

use App\Enums\Hubungan;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anak yang tertaut ke wali murid, beserta hubungan wali dengan anak dari pivot `murid_wali`. Murid harus
 * dimuat lewat relasi `WaliMurid::murid()` supaya pivotnya ada.
 * Data murid lengkap (termasuk catatan khusus) disajikan `GET /murid/{id}`.
 *
 * @mixin Murid
 */
class AnakWaliResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $kelas = $this->relationLoaded('kelasAktif') ? $this->kelasAktif->first() : null;
        /** @var MuridWali $pivot */
        $pivot = $this->resource->getRelation('pivot');

        return [
            'id' => $this->id,
            'nis' => $this->nis,
            'nama_lengkap' => $this->nama_lengkap,
            'nama_panggilan' => $this->nama_panggilan,
            'jenis_kelamin' => $this->jenis_kelamin,
            'tanggal_lahir' => $this->tanggal_lahir->toDateString(),
            /** @var array{id: int, nama: string}|null */
            'kelas' => $kelas === null ? null : ['id' => $kelas->id, 'nama' => $kelas->nama],
            /** @var string|null */
            'foto_url' => app(MediaService::class)->urlPrivat($this->foto_path),
            /** @var Hubungan */
            'hubungan' => $pivot->hubungan,
            /** @var bool */
            'is_kontak_utama' => $pivot->is_kontak_utama,
        ];
    }
}
