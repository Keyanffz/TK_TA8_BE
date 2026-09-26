<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pengumuman;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `kelas` dan `murid` (daftar sasaran) hanya untuk Kepala Sekolah dan penulis. Wali murid tidak boleh melihat
 * nama anak lain yang ikut disasar, misalnya pengumuman untuk murid yang menunggak.
 *
 * @mixin Pengumuman
 */
class PengumumanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $bolehLihatSasaran = $user !== null && ($user->role === Role::SuperAdmin || $user->id === $this->penulis_id);

        return [
            'id' => $this->id,
            'judul' => $this->judul,
            'slug' => $this->slug,
            /** HTML yang sudah disanitasi. */
            'isi' => $this->isi,
            'target' => $this->target,
            /** Hanya untuk Kepala Sekolah dan penulis. */
            'kelas' => $this->when($bolehLihatSasaran && $this->relationLoaded('kelas'), fn () => $this->kelas
                ->map(fn (Kelas $kelas): array => ['id' => $kelas->id, 'nama' => $kelas->nama])->values()->all()),
            /** Hanya untuk Kepala Sekolah dan penulis. */
            'murid' => $this->when($bolehLihatSasaran && $this->relationLoaded('murid'), fn () => $this->murid
                ->map(fn (Murid $murid): array => ['id' => $murid->id, 'nama_lengkap' => $murid->nama_lengkap])->values()->all()),
            'is_publik' => $this->is_publik,
            'is_pinned' => $this->is_pinned,
            'penulis' => $this->whenLoaded('penulis', fn () => ['id' => $this->penulis->id, 'nama' => $this->penulis->name]),
            /** Null = draft. */
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
