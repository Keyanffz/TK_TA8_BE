<?php

namespace App\Http\Resources;

use App\Models\Pendaftaran;
use App\Models\PendaftaranDokumen;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pendaftaran hanya dikirim ke Kepala Sekolah dan wali pendaftarnya, jadi URL dokumen (signed URL file
 * private) aman dibuat di sini. `dokumen` dan `wali` hanya ada di detail.
 *
 * @mixin Pendaftaran
 */
class PendaftaranResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);

        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'status' => $this->status,
            'tahun_ajaran' => $this->whenLoaded('tahunAjaran', fn () => ['id' => $this->tahunAjaran->id, 'nama' => $this->tahunAjaran->nama]),
            'tingkat_tujuan' => $this->tingkat_tujuan,
            'hubungan' => $this->hubungan,
            'nama_lengkap' => $this->nama_lengkap,
            'nama_panggilan' => $this->nama_panggilan,
            'jenis_kelamin' => $this->jenis_kelamin,
            'tempat_lahir' => $this->tempat_lahir,
            'tanggal_lahir' => $this->tanggal_lahir->toDateString(),
            'nik' => $this->nik,
            'agama' => $this->agama,
            'alamat' => $this->alamat,
            'nama_ayah' => $this->nama_ayah,
            'pekerjaan_ayah' => $this->pekerjaan_ayah,
            'nama_ibu' => $this->nama_ibu,
            'pekerjaan_ibu' => $this->pekerjaan_ibu,
            'no_hp' => $this->no_hp,
            /** Alasan penolakan. */
            'catatan' => $this->catatan,
            'wali' => $this->whenLoaded('waliMurid', fn () => [
                'id' => $this->waliMurid->id,
                'nama' => $this->waliMurid->user->name,
                'email' => $this->waliMurid->user->email,
                'no_hp' => $this->waliMurid->user->no_hp,
            ]),
            'dokumen' => $this->whenLoaded('dokumen', fn () => $this->dokumen->map(fn (PendaftaranDokumen $dokumen): array => [
                'id' => $dokumen->id,
                'jenis' => $dokumen->jenis,
                'url' => $media->urlPrivat($dokumen->path),
            ])->values()->all()),
            'murid' => $this->whenLoaded('murid', fn () => $this->murid === null ? null : ['id' => $this->murid->id, 'nis' => $this->murid->nis]),
            'diproses_at' => $this->diproses_at,
            'created_at' => $this->created_at,
        ];
    }
}
