<?php

namespace App\Http\Resources;

use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Services\MediaService;
use Illuminate\Http\Request;

/**
 * Detail kelas (`Kelas::muatDetail()`): bentuk daftar ditambah semua murid yang pernah ditempatkan, termasuk
 * yang penempatannya sudah selesai (`status_kelas` selain `aktif`).
 *
 * @mixin Kelas
 */
class KelasDetailResource extends KelasResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'murid' => $this->daftarMurid(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function daftarMurid(): array
    {
        $media = app(MediaService::class);

        return $this->murid
            ->map(function (Murid $murid) use ($media): array {
                /** @var KelasMurid $penempatan */
                $penempatan = $murid->getRelation('pivot');

                return [
                    'id' => $murid->id,
                    'nis' => $murid->nis,
                    'nama_lengkap' => $murid->nama_lengkap,
                    'nama_panggilan' => $murid->nama_panggilan,
                    'jenis_kelamin' => $murid->jenis_kelamin,
                    'status' => $murid->status,
                    'status_kelas' => $penempatan->status,
                    /** @var string|null */
                    'foto_url' => $media->urlPrivat($murid->foto_path),
                ];
            })
            ->values()
            ->all();
    }
}
