<?php

namespace App\Http\Resources;

use App\Enums\Hubungan;
use App\Enums\Imunisasi;
use App\Enums\ModulIzin;
use App\Enums\Role;
use App\Enums\StatusMurid;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Models\RiwayatStatusMurid;
use App\Models\User;
use App\Models\WaliMurid;
use App\Support\KelengkapanFormulir;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Detail murid (`Murid::muatDetail()`): bentuk daftar ditambah isian formulir data murid, centang berkas, wali yang
 * tertaut, dan riwayat status. Ayah dan ibu saling melihat kontak masing-masing. NIK anak hanya dikirim ke Kepala
 * Sekolah, pemegang izin data murid, dan wali anak itu; guru pengampu tanpa izin tidak menerima field `nik`.
 *
 * @mixin Murid
 */
class MuridDetailResource extends MuridResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            /** @var string|null */
            'nik' => $this->when($this->bolehLihatNik($request->user()), $this->nik),
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
            /** Sentimeter, data saat masuk (terpisah dari tinggi badan di rapor). */
            'tinggi_badan' => $this->tinggi_badan,
            /** Kilogram, data saat masuk. */
            'berat_badan' => $this->berat_badan,
            /** @var list<Imunisasi>|null */
            'imunisasi' => $this->imunisasi,
            'imunisasi_lainnya' => $this->imunisasi_lainnya,
            'ciri_khusus' => $this->ciri_khusus,
            /** Orang tua yang nomor HP dan namanya dipakai akun wali otomatis saat NIS diisi. */
            'kontak_utama' => $this->kontak_utama,
            /**
             * Isian formulir per peran; null kalau belum diisi. Terpisah dari `wali` (akun wali murid yang tertaut).
             *
             * @var array{ayah: OrangTuaResource|null, ibu: OrangTuaResource|null, wali: OrangTuaResource|null}
             */
            'orang_tua' => OrangTuaResource::perPeran($this->orangTua),
            /**
             * Field wajib formulir yang masih kosong, dengan nama field request (`nik`, `ayah.pekerjaan`).
             *
             * @var list<string>
             */
            'data_kurang' => array_keys(KelengkapanFormulir::untukMurid($this->resource)),
            'berkas' => new BerkasFisikResource($this->resource),
            /** @var list<array{id: int, nama: string, email: string|null, username: string|null, no_hp: string|null, hubungan: Hubungan, is_kontak_utama: bool, tertaut_at: Carbon|null}> */
            'wali' => $this->waliMurid->map(fn (WaliMurid $wali): array => $this->ringkasWali($wali))->values()->all(),
            /**
             * Perubahan status, terbaru dulu. `diubah_oleh` null kalau akun pengubahnya sudah dihapus.
             *
             * @var list<array{id: int, status_lama: StatusMurid, status_baru: StatusMurid, tanggal: string, tahun_lulus: int|null, sekolah_tujuan: string|null, keterangan: string|null, diubah_oleh: array{id: int, nama: string}|null, created_at: Carbon|null}>
             */
            'riwayat_status' => $this->riwayatStatus->map(fn (RiwayatStatusMurid $riwayat): array => [
                'id' => $riwayat->id,
                'status_lama' => $riwayat->status_lama,
                'status_baru' => $riwayat->status_baru,
                'tanggal' => $riwayat->tanggal->toDateString(),
                'tahun_lulus' => $riwayat->tahun_lulus,
                'sekolah_tujuan' => $riwayat->sekolah_tujuan,
                'keterangan' => $riwayat->keterangan,
                'diubah_oleh' => $riwayat->pengubah === null ? null : ['id' => $riwayat->pengubah->id, 'nama' => $riwayat->pengubah->name],
                'created_at' => $riwayat->created_at,
            ])->values()->all(),
        ];
    }

    private function bolehLihatNik(?User $pengguna): bool
    {
        return $pengguna !== null && ($pengguna->role === Role::WaliMurid || $pengguna->punyaIzin(ModulIzin::DataMurid));
    }

    /**
     * @return array<string, mixed>
     */
    private function ringkasWali(WaliMurid $wali): array
    {
        /** @var MuridWali $tautan */
        $tautan = $wali->getRelation('pivot');

        return [
            'id' => $wali->id,
            'nama' => $wali->user->name,
            'email' => $wali->user->email,
            'username' => $wali->user->username,
            'no_hp' => $wali->user->no_hp,
            'hubungan' => $tautan->hubungan,
            'is_kontak_utama' => $tautan->is_kontak_utama,
            'tertaut_at' => $tautan->created_at,
        ];
    }
}
