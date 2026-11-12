<?php

namespace App\Http\Resources;

use App\Models\Absensi;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Butuh relasi `pengoreksi`. Foto diambil lewat `GET /absensi/{id}/foto`; `ada_foto` false untuk baris
 * `tidak_hadir` dan foto yang sudah melewati masa simpan. Resource ini hanya dikirim ke pemilik absensi, Kepala
 * Sekolah, dan pemegang izin laporan absensi, jadi signed URL lampiran aman dibuat di sini.
 *
 * @mixin Absensi
 */
class AbsensiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'tanggal' => $this->tanggal->toDateString(),
            'jenis' => $this->jenis,
            'status' => $this->status,
            'waktu' => $this->waktu,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'akurasi_meter' => $this->akurasi_meter,
            'jarak_meter' => $this->jarak_meter,
            'ada_foto' => $this->foto_path !== null,
            'keterangan' => $this->keterangan,
            'pulang_awal' => $this->pulang_awal,
            /** True kalau dikirim peserta lewat akses absen susulan dari Kepala Sekolah. */
            'susulan' => $this->susulan,
            /** Foto atau dokumen dari Kepala Sekolah saat koreksi atau pencatatan. `url` adalah signed URL yang berlaku 30 menit. */
            'lampiran' => $this->lampiran_path === null ? null : [
                'jenis' => str_ends_with($this->lampiran_path, '.pdf') ? 'pdf' : 'gambar',
                'url' => (string) app(MediaService::class)->urlPrivat($this->lampiran_path),
            ],
            'catatan_koreksi' => $this->catatan_koreksi,
            /** @var array{id: int, nama: string}|null */
            'dikoreksi_oleh' => $this->pengoreksi === null ? null : ['id' => $this->pengoreksi->id, 'nama' => $this->pengoreksi->name],
            'dikoreksi_at' => $this->dikoreksi_at,
        ];
    }
}
