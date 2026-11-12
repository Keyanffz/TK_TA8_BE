<?php

namespace App\Http\Resources;

use App\Models\Pendaftaran;
use Illuminate\Http\Request;

/**
 * Detail pendaftaran: bentuk daftar ditambah wali pendaftar (butuh relasi `waliMurid.user`).
 *
 * @mixin Pendaftaran
 */
class PendaftaranDetailResource extends PendaftaranResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            /**
             * Wali pendaftar; null untuk pendaftaran tanpa login yang belum diterima.
             *
             * @var array{id: int, nama: string, username: string|null, no_hp: string|null}|null
             */
            'wali' => $this->waliMurid === null ? null : [
                'id' => $this->waliMurid->id,
                'nama' => $this->waliMurid->user->name,
                'username' => $this->waliMurid->user->username,
                'no_hp' => $this->waliMurid->user->no_hp,
            ],
        ];
    }
}
