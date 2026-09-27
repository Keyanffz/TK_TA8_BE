<?php

namespace App\Http\Resources;

use App\Enums\Hubungan;
use App\Enums\Role;
use App\Enums\Tingkat;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Models\WaliMurid;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Murid hanya sampai ke pengguna yang lolos `Murid::visibleTo` (Kepala Sekolah, guru pengampu, wali anak
 * itu), sehingga `catatan_khusus` aman ditampilkan (B4). Kode tautan hanya untuk Kepala Sekolah.
 * `wali` hanya ada di detail murid.
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
            /** @var list<array{id: int, nama: string, email: string, no_hp: string|null, hubungan: Hubungan, is_kontak_utama: bool, tertaut_at: Carbon|null}> */
            'wali' => $this->whenLoaded('waliMurid', fn () => $this->waliMurid->map(fn (WaliMurid $wali): array => $this->ringkasWali($wali))->values()->all()),
            /** Hanya untuk Kepala Sekolah. */
            'kode_tautan' => $this->when($kepalaSekolah, fn () => $this->kode_tautan),
            /** Hanya untuk Kepala Sekolah. */
            'kode_tautan_expired_at' => $this->when($kepalaSekolah, fn () => $this->kode_tautan_expired_at),
            'created_at' => $this->created_at,
        ];
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
            'no_hp' => $wali->user->no_hp,
            'hubungan' => $tautan->hubungan,
            'is_kontak_utama' => $tautan->is_kontak_utama,
            'tertaut_at' => $tautan->created_at,
        ];
    }
}
