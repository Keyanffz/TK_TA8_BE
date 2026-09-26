<?php

namespace App\Http\Resources;

use App\Models\Kelas;
use App\Models\Murid;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk `user` di respons auth (A7): login, login Google, `/auth/me`, dan pembaruan profil.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['guru', 'waliMurid.murid.kelasAktif']);
        $media = app(MediaService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'no_hp' => $this->no_hp,
            'avatar_url' => $media->urlPublik($this->avatar_path),
            'guru' => $this->guru === null ? null : [
                'id' => $this->guru->id,
                'bisa_kelola_keuangan' => $this->guru->bisa_kelola_keuangan,
                'kelas_diampu' => Kelas::query()->diampuOleh($this->resource)->orderBy('nama')->get(['kelas.id', 'kelas.nama'])
                    ->map(fn (Kelas $kelas): array => ['id' => $kelas->id, 'nama' => $kelas->nama])
                    ->all(),
            ],
            'wali_murid' => $this->waliMurid === null ? null : [
                'id' => $this->waliMurid->id,
                'profil_lengkap' => $this->waliMurid->profil_lengkap,
                'anak' => $this->waliMurid->murid
                    ->map(fn (Murid $anak): array => [
                        'id' => $anak->id,
                        'nama_panggilan' => $anak->nama_panggilan,
                        'kelas' => $anak->kelasAktif->first()?->nama,
                        'foto_url' => $media->urlPrivat($anak->foto_path),
                    ])
                    ->values()
                    ->all(),
            ],
            'permissions' => [
                'kelola_keuangan' => $this->bisaKelolaKeuangan(),
            ],
        ];
    }
}
