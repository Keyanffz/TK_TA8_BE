<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Pembayaran;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `bukti_url` hanya untuk petugas keuangan dan wali murid (yang hanya bisa melihat pembayaran anaknya).
 * Guru tanpa izin keuangan melihat riwayat pembayaran murid kelasnya tanpa foto bukti transfer.
 *
 * @mixin Pembayaran
 */
class PembayaranResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $penampil = $request->user();
        $bolehLihatBukti = $penampil instanceof User
            && ($penampil->role === Role::WaliMurid || $penampil->bisaKelolaKeuangan());

        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'tagihan_id' => $this->tagihan_id,
            'tagihan' => new TagihanResource($this->whenLoaded('tagihan')),
            'metode' => $this->metode,
            'jumlah' => $this->jumlah,
            'tanggal_bayar' => $this->tanggal_bayar->toDateString(),
            'bukti_url' => $bolehLihatBukti ? app(MediaService::class)->urlPrivat($this->bukti_path) : null,
            'bank_pengirim' => $this->bank_pengirim,
            'nama_pengirim' => $this->nama_pengirim,
            'status' => $this->status,
            'alasan_penolakan' => $this->alasan_penolakan,
            'dibayar_oleh' => $this->whenLoaded('pembayar', fn () => $this->pembayar === null ? null : ['id' => $this->pembayar->id, 'nama' => $this->pembayar->name]),
            'diverifikasi_oleh' => $this->whenLoaded('verifikator', fn () => $this->verifikator === null ? null : ['id' => $this->verifikator->id, 'nama' => $this->verifikator->name]),
            'diverifikasi_at' => $this->diverifikasi_at,
            'created_at' => $this->created_at,
        ];
    }
}
