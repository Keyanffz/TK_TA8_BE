<?php

namespace App\Http\Resources;

use App\Models\Pendaftaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Status pendaftaran untuk pendaftar tanpa login: hanya data yang dibutuhkan untuk memantau status, tanpa NIK,
 * alamat, dokumen, dan NIS murid (NIS adalah username akun wali, diberikan sekolah lewat kartu akun).
 * Butuh relasi `tahunAjaran`.
 *
 * @mixin Pendaftaran
 */
class PendaftaranPublikResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kode' => $this->kode,
            'status' => $this->status,
            'nama_panggilan' => $this->nama_panggilan,
            'tingkat_tujuan' => $this->tingkat_tujuan,
            'tahun_ajaran' => ['id' => $this->tahunAjaran->id, 'nama' => $this->tahunAjaran->nama],
            /** Alasan penolakan. */
            'catatan' => $this->catatan,
            'diproses_at' => $this->diproses_at,
            'created_at' => $this->created_at,
        ];
    }
}
