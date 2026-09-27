<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Data akun yang di-nest di GuruResource dan WaliMuridResource (manajemen oleh Kepala Sekolah).
 *
 * @mixin User
 */
class AkunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** Kosong untuk wali murid. */
            'email' => $this->email,
            /** NIS anak untuk login wali murid; kosong untuk guru. */
            'username' => $this->username,
            'role' => $this->role,
            'status' => $this->status,
            'wajib_ganti_password' => $this->wajib_ganti_password,
            'no_hp' => $this->no_hp,
            /** @var string|null */
            'avatar_url' => app(MediaService::class)->urlPublik($this->avatar_path),
            'last_login_at' => $this->last_login_at,
        ];
    }
}
