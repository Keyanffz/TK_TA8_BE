<?php

namespace App\Http\Resources;

use App\Models\Pendaftaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk pendaftaran di daftar. Butuh relasi `tahunAjaran` dan `murid`; data wali pendaftar dan dokumen ada di
 * `PendaftaranDetailResource`.
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
        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'status' => $this->status,
            'tahun_ajaran' => ['id' => $this->tahunAjaran->id, 'nama' => $this->tahunAjaran->nama],
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
            /**
             * Murid yang dibuat saat pendaftaran diterima.
             *
             * @var array{id: int, nis: string}|null
             */
            'murid' => $this->murid === null ? null : ['id' => $this->murid->id, 'nis' => $this->murid->nis],
            'diproses_at' => $this->diproses_at,
            'created_at' => $this->created_at,
        ];
    }
}
