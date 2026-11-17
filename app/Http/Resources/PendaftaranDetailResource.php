<?php

namespace App\Http\Resources;

use App\Enums\Imunisasi;
use App\Models\Pendaftaran;
use Illuminate\Http\Request;

/**
 * Detail pendaftaran: bentuk daftar ditambah NIK anak, isian formulir data murid, centang berkas, dan wali pendaftar.
 * Butuh relasi `waliMurid.user`, `orangTua`, `kkDiterimaOleh`, dan `aktaDiterimaOleh`. Waktu pendaftar menyetujui
 * pernyataan "data yang diisi benar" adalah `created_at`.
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
        return [
            ...parent::toArray($request),
            'nik' => $this->nik,
            'anak_ke' => $this->anak_ke,
            'desa_kelurahan' => $this->desa_kelurahan,
            'kecamatan' => $this->kecamatan,
            'kabupaten_kota' => $this->kabupaten_kota,
            'provinsi' => $this->provinsi,
            'asal_sekolah' => $this->asal_sekolah,
            'kewarganegaraan' => $this->kewarganegaraan,
            'jumlah_saudara_kandung' => $this->jumlah_saudara_kandung,
            'jumlah_saudara_tiri' => $this->jumlah_saudara_tiri,
            'jumlah_saudara_angkat' => $this->jumlah_saudara_angkat,
            'status_yatim_piatu' => $this->status_yatim_piatu,
            'bahasa_sehari_hari' => $this->bahasa_sehari_hari,
            /** Kilometer. */
            'jarak_rumah_km' => $this->jarak_rumah_km,
            'golongan_darah' => $this->golongan_darah,
            'riwayat_penyakit' => $this->riwayat_penyakit,
            /** Sentimeter. */
            'tinggi_badan' => $this->tinggi_badan,
            /** Kilogram. */
            'berat_badan' => $this->berat_badan,
            /** @var list<Imunisasi>|null */
            'imunisasi' => $this->imunisasi,
            'imunisasi_lainnya' => $this->imunisasi_lainnya,
            'ciri_khusus' => $this->ciri_khusus,
            'kontak_utama' => $this->kontak_utama,
            /** @var array{ayah: OrangTuaResource|null, ibu: OrangTuaResource|null, wali: OrangTuaResource|null} */
            'orang_tua' => OrangTuaResource::perPeran($this->orangTua),
            'berkas' => new BerkasFisikResource($this->resource),
            /** Status sebelum mengundurkan diri; pendaftar yang mundur sebelum diterima bisa dikembalikan ke status ini. */
            'status_sebelum_mundur' => $this->status_sebelum_mundur,
            /**
             * Wali pendaftar; null untuk pendaftaran tanpa login yang belum diterima.
             *
             * @var array{id: int, nama: string, username: string|null, no_hp: string|null}|null
             */
            'wali' => $this->waliMurid === null ? null : [
                'id' => $this->waliMurid->id,
                'nama' => $this->waliMurid->user->name,
                'username' => $this->waliMurid->user->username,
                'no_hp' => $this->waliMurid->user->no_hp,
            ],
        ];
    }
}
