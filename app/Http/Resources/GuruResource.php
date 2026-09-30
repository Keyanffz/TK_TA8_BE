<?php

namespace App\Http\Resources;

use App\Models\Guru;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guru
 */
class GuruResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new AkunResource($this->user),
            'nip' => $this->nip,
            'nuptk' => $this->nuptk,
            'jenis_kelamin' => $this->jenis_kelamin,
            'tempat_lahir' => $this->tempat_lahir,
            'tanggal_lahir' => $this->tanggal_lahir?->toDateString(),
            'alamat' => $this->alamat,
            'pendidikan_terakhir' => $this->pendidikan_terakhir,
            'jabatan' => $this->jabatan,
            /** @var string|null */
            'foto_url' => app(MediaService::class)->urlPublik($this->foto_path),
            'bisa_kelola_keuangan' => $this->bisa_kelola_keuangan,
            'tampil_di_landing' => $this->tampil_di_landing,
            'created_at' => $this->created_at,
        ];
    }
}
