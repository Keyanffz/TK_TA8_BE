<?php

namespace App\Http\Resources;

use App\Enums\Hubungan;
use App\Models\Murid;
use App\Models\MuridWali;
use App\Models\WaliMurid;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Detail murid: bentuk daftar ditambah wali yang tertaut (butuh relasi `waliMurid.user`). Ayah dan ibu saling
 * melihat kontak masing-masing.
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
            /** @var list<array{id: int, nama: string, email: string, no_hp: string|null, hubungan: Hubungan, is_kontak_utama: bool, tertaut_at: Carbon|null}> */
            'wali' => $this->waliMurid->map(fn (WaliMurid $wali): array => $this->ringkasWali($wali))->values()->all(),
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
