<?php

namespace App\Http\Resources;

use App\Models\Tagihan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk tagihan di daftar dan hasil aksi. Butuh relasi `murid` dan `jenisTagihan` (`murid.kelasAktif` untuk
 * nama kelas). Riwayat pembayaran dan rekening sekolah ada di `TagihanDetailResource`.
 *
 * @mixin Tagihan
 */
class TagihanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $kelas = $this->murid->relationLoaded('kelasAktif') ? $this->murid->kelasAktif->first() : null;

        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'murid' => [
                'id' => $this->murid->id,
                'nis' => $this->murid->nis,
                'nama_lengkap' => $this->murid->nama_lengkap,
                'nama_panggilan' => $this->murid->nama_panggilan,
                /** @var array{id: int, nama: string}|null */
                'kelas' => $kelas === null ? null : ['id' => $kelas->id, 'nama' => $kelas->nama],
            ],
            'jenis_tagihan' => [
                'id' => $this->jenisTagihan->id,
                'nama' => $this->jenisTagihan->nama,
                'periode' => $this->jenisTagihan->periode,
            ],
            'tahun_ajaran_id' => $this->tahun_ajaran_id,
            'periode' => $this->periode?->toDateString(),
            'nominal' => $this->nominal,
            'potongan' => $this->potongan,
            'total' => $this->total,
            'jatuh_tempo' => $this->jatuh_tempo->toDateString(),
            'status' => $this->status,
            'lunas_at' => $this->lunas_at,
            'catatan' => $this->catatan,
            'created_at' => $this->created_at,
        ];
    }
}
