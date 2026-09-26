<?php

namespace App\Http\Resources;

use App\Models\Tagihan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `pembayaran` (riwayat) dan `rekening` (rekening sekolah) hanya ada di detail tagihan.
 *
 * @mixin Tagihan
 */
class TagihanResource extends JsonResource
{
    /** @var list<array{bank: string, nomor: string, atas_nama: string}>|null */
    private ?array $rekening = null;

    /**
     * @param  list<array{bank: string, nomor: string, atas_nama: string}>  $rekening
     */
    public function denganRekening(array $rekening): static
    {
        $this->rekening = $rekening;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'murid' => $this->whenLoaded('murid', fn () => [
                'id' => $this->murid->id,
                'nis' => $this->murid->nis,
                'nama_lengkap' => $this->murid->nama_lengkap,
                'nama_panggilan' => $this->murid->nama_panggilan,
                'kelas' => $this->murid->relationLoaded('kelasAktif') && $this->murid->kelasAktif->isNotEmpty()
                    ? ['id' => $this->murid->kelasAktif->first()->id, 'nama' => $this->murid->kelasAktif->first()->nama]
                    : null,
            ]),
            'jenis_tagihan' => $this->whenLoaded('jenisTagihan', fn () => [
                'id' => $this->jenisTagihan->id,
                'nama' => $this->jenisTagihan->nama,
                'periode' => $this->jenisTagihan->periode,
            ]),
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
            'pembayaran' => PembayaranResource::collection($this->whenLoaded('pembayaran')),
            /** Hanya di detail tagihan. */
            'rekening' => $this->when($this->rekening !== null, fn () => $this->rekening),
        ];
    }
}
