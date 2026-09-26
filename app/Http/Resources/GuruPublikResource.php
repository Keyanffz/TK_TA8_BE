<?php

namespace App\Http\Resources;

use App\Models\Guru;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guru
 */
class GuruPublikResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->user->name,
            'jabatan' => $this->jabatan,
            'foto_url' => app(MediaService::class)->urlPublik($this->foto_path),
        ];
    }
}
