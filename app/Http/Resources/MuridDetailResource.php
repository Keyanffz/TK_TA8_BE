<?php

namespace App\Http\Resources;

use App\Enums\Hubungan;
use App\Enums\StatusMurid;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Models\RiwayatStatusMurid;
use App\Models\WaliMurid;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Detail murid (`Murid::muatDetail()`): bentuk daftar ditambah wali yang tertaut dan riwayat status. Ayah dan ibu
 * saling melihat kontak masing-masing.
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
