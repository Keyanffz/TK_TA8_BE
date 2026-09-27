<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Enums\Tingkat;
use App\Models\Murid;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Murid hanya sampai ke pengguna yang lolos `Murid::visibleTo` (Kepala Sekolah, guru pengampu, wali anak
 * itu), sehingga `catatan_khusus` aman ditampilkan (B4). Kode tautan hanya untuk Kepala Sekolah.
 * Wali yang tertaut ada di `MuridDetailResource`.
 *
 * @mixin Murid
 */
class MuridResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $kelas = $this->relationLoaded('kelasAktif') ? $this->kelasAktif->first() : null;
        $kepalaSekolah = $request->user()?->role === Role::SuperAdmin;

        return [
            'id' => $this->id,
            'nis' => $this->nis,
            'nisn' => $this->nisn,
            'nik' => $this->nik,
            'nama_lengkap' => $this->nama_lengkap,
            'nama_panggilan' => $this->nama_panggilan,
            'jenis_kelamin' => $this->jenis_kelamin,
            'tempat_lahir' => $this->tempat_lahir,
            'tanggal_lahir' => $this->tanggal_lahir->toDateString(),
            'agama' => $this->agama,
            'alamat' => $this->alamat,
            'anak_ke' => $this->anak_ke,
            /** @var string|null */
            'foto_url' => app(MediaService::class)->urlPrivat($this->foto_path),
            'catatan_khusus' => $this->catatan_khusus,
            'status' => $this->status,
            'tanggal_masuk' => $this->tanggal_masuk->toDateString(),
            'tanggal_keluar' => $this->tanggal_keluar?->toDateString(),
            /** @var array{id: int, nama: string, tingkat: Tingkat}|null */
            'kelas' => $kelas === null ? null : ['id' => $kelas->id, 'nama' => $kelas->nama, 'tingkat' => $kelas->tingkat],
            /** Hanya untuk Kepala Sekolah. */
            'kode_tautan' => $this->when($kepalaSekolah, fn () => $this->kode_tautan),
            /** Hanya untuk Kepala Sekolah. */
            'kode_tautan_expired_at' => $this->when($kepalaSekolah, fn () => $this->kode_tautan_expired_at),
            'created_at' => $this->created_at,
        ];
    }
}
