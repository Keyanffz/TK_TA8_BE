<?php

namespace App\Http\Resources;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `murid` hanya ada di detail kelas (`GET /kelas/{id}`), termasuk murid yang penempatannya sudah
 * selesai (`status_kelas` selain `aktif`).
 *
 * @mixin Kelas
 */
class KelasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'tingkat' => $this->tingkat,
            'kapasitas' => $this->kapasitas,
            'jumlah_murid' => $this->whenCounted('muridAktif'),
            'tahun_ajaran' => $this->whenLoaded('tahunAjaran', fn () => [
                'id' => $this->tahunAjaran->id,
                'nama' => $this->tahunAjaran->nama,
                'is_aktif' => $this->tahunAjaran->is_aktif,
            ]),
            'wali_kelas' => $this->whenLoaded('waliKelas', fn () => $this->ringkasGuru($this->waliKelas)),
            'guru_pendamping' => $this->whenLoaded('guruPendamping', fn () => $this->ringkasGuru($this->guruPendamping)),
            'murid' => $this->whenLoaded('murid', fn () => $this->daftarMurid()),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array{id: int, nama: string}|null
     */
    private function ringkasGuru(?Guru $guru): ?array
    {
        return $guru === null ? null : ['id' => $guru->id, 'nama' => $guru->user->name];
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
                    'foto_url' => $media->urlPrivat($murid->foto_path),
                ];
            })
            ->values()
            ->all();
    }
}
