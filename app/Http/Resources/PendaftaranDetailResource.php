<?php

namespace App\Http\Resources;

use App\Models\Pendaftaran;
use App\Models\PendaftaranDokumen;
use App\Services\MediaService;
use Illuminate\Http\Request;

/**
 * Detail pendaftaran: bentuk daftar ditambah wali pendaftar (butuh relasi `waliMurid.user`) dan dokumen.
 * Pendaftaran hanya dikirim ke Kepala Sekolah dan wali pendaftarnya, jadi URL dokumen (signed URL file
 * private) aman dibuat di sini.
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
        $media = app(MediaService::class);

        return [
            ...parent::toArray($request),
            'wali' => [
                'id' => $this->waliMurid->id,
                'nama' => $this->waliMurid->user->name,
                'email' => $this->waliMurid->user->email,
                'no_hp' => $this->waliMurid->user->no_hp,
            ],
            'dokumen' => $this->dokumen->map(fn (PendaftaranDokumen $dokumen): array => [
                'id' => $dokumen->id,
                'jenis' => $dokumen->jenis,
                'url' => $media->urlPrivat($dokumen->path),
            ])->values()->all(),
        ];
    }
}
