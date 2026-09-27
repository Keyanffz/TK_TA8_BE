<?php

namespace App\Http\Resources;

use App\Models\Guru;
use App\Models\Kelas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk kelas di daftar. Butuh relasi `tahunAjaran`, `waliKelas.user`, `guruPendamping.user`, dan
 * `withCount('muridAktif')`. Daftar murid ada di `KelasDetailResource`.
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
            /** Murid dengan penempatan `aktif`. */
            'jumlah_murid' => (int) $this->murid_aktif_count,
            'tahun_ajaran' => [
                'id' => $this->tahunAjaran->id,
                'nama' => $this->tahunAjaran->nama,
                'is_aktif' => $this->tahunAjaran->is_aktif,
            ],
            'wali_kelas' => $this->ringkasGuru($this->waliKelas),
            'guru_pendamping' => $this->ringkasGuru($this->guruPendamping),
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
}
