<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rapor hanya dikirim ke pengguna yang lolos `Rapor::visibleTo`, jadi `foto_url` detail aman dibuat di sini.
 * `catatan_revisi` adalah catatan internal Kepala Sekolah untuk guru dan tidak dikirim ke wali murid.
 * `detail` hanya ada di detail rapor.
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
            'murid' => $this->whenLoaded('murid', fn () => [
                'id' => $this->murid->id,
                'nis' => $this->murid->nis,
                'nama_lengkap' => $this->murid->nama_lengkap,
                'nama_panggilan' => $this->murid->nama_panggilan,
            ]),
            'kelas' => $this->whenLoaded('kelas', fn () => ['id' => $this->kelas->id, 'nama' => $this->kelas->nama]),
            'tahun_ajaran' => $this->whenLoaded('tahunAjaran', fn () => ['id' => $this->tahunAjaran->id, 'nama' => $this->tahunAjaran->nama]),
            'semester' => $this->semester,
            'tinggi_badan' => $this->tinggi_badan === null ? null : (float) $this->tinggi_badan,
            'berat_badan' => $this->berat_badan === null ? null : (float) $this->berat_badan,
            'catatan_guru' => $this->catatan_guru,
            'status' => $this->status,
            /** Tidak dikirim ke wali murid. */
            'catatan_revisi' => $this->when($bukanWali, fn () => $this->catatan_revisi),
            'pembuat' => $this->whenLoaded('pembuat', fn () => ['id' => $this->pembuat->id, 'nama' => $this->pembuat->user->name]),
            'diajukan_at' => $this->diajukan_at,
            'terbit_at' => $this->terbit_at,
            'detail' => $this->whenLoaded('detail', fn () => $this->daftarDetail()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function daftarDetail(): array
    {
        $media = app(MediaService::class);

        return $this->detail
            ->sortBy(fn (RaporDetail $detail): int => $detail->elemenPenilaian->urutan)
            ->map(fn (RaporDetail $detail): array => [
                'id' => $detail->id,
                'elemen' => [
                    'id' => $detail->elemenPenilaian->id,
                    'kode' => $detail->elemenPenilaian->kode,
                    'nama' => $detail->elemenPenilaian->nama,
                ],
                'deskripsi' => $detail->deskripsi,
                'foto_url' => $media->urlPrivat($detail->foto_path),
            ])
            ->values()
            ->all();
    }
}
