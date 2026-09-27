<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Rapor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `catatan_revisi` adalah catatan internal Kepala Sekolah untuk guru dan tidak dikirim ke wali murid.
 * Butuh relasi `murid`, `kelas`, `tahunAjaran`, dan `pembuat.user`; isi per elemen ada di `RaporDetailResource`.
 *
 * @mixin Rapor
 */
class RaporResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bukanWali = $request->user()?->role !== Role::WaliMurid;

        return [
            'id' => $this->id,
            'murid' => [
                'id' => $this->murid->id,
                'nis' => $this->murid->nis,
                'nama_lengkap' => $this->murid->nama_lengkap,
                'nama_panggilan' => $this->murid->nama_panggilan,
            ],
            'kelas' => ['id' => $this->kelas->id, 'nama' => $this->kelas->nama],
            'tahun_ajaran' => ['id' => $this->tahunAjaran->id, 'nama' => $this->tahunAjaran->nama],
            'semester' => $this->semester,
            'tinggi_badan' => $this->tinggi_badan === null ? null : (float) $this->tinggi_badan,
            'berat_badan' => $this->berat_badan === null ? null : (float) $this->berat_badan,
            'catatan_guru' => $this->catatan_guru,
            'status' => $this->status,
            /** Tidak dikirim ke wali murid. */
            'catatan_revisi' => $this->when($bukanWali, fn () => $this->catatan_revisi),
            'pembuat' => ['id' => $this->pembuat->id, 'nama' => $this->pembuat->user->name],
            'diajukan_at' => $this->diajukan_at,
            'terbit_at' => $this->terbit_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
