<?php

namespace App\Http\Resources;

use App\Models\OrangTua;
use App\Support\FormulirMurid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Isian ayah, ibu, atau wali dari formulir data murid. `status` hanya untuk ayah dan ibu, `hubungan` hanya untuk
 * wali. Kalau `alamat_sama_dengan_anak`, `alamat_rumah` kosong dan alamat anak yang berlaku.
 *
 * @mixin OrangTua
 */
class OrangTuaResource extends JsonResource
{
    /**
     * @param  Collection<int, OrangTua>  $orangTua
     * @return array{ayah: self|null, ibu: self|null, wali: self|null}
     */
    public static function perPeran(Collection $orangTua): array
    {
        $hasil = array_fill_keys(FormulirMurid::PERAN, null);

        foreach ($orangTua as $orang) {
            $hasil[$orang->peran->value] = new self($orang);
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'hubungan' => $this->hubungan,
            'hubungan_lainnya' => $this->hubungan_lainnya,
            'nama' => $this->nama,
            'tempat_lahir' => $this->tempat_lahir,
            'tanggal_lahir' => $this->tanggal_lahir?->toDateString(),
            /** @var string|null */
            'agama' => $this->agama,
            'kewarganegaraan' => $this->kewarganegaraan,
            'pendidikan_terakhir' => $this->pendidikan_terakhir,
            'pekerjaan' => $this->pekerjaan,
            'pekerjaan_lainnya' => $this->pekerjaan_lainnya,
            'penghasilan' => $this->penghasilan,
            'alamat_sama_dengan_anak' => $this->alamat_sama_dengan_anak,
            'alamat_rumah' => $this->alamat_rumah,
            'alamat_kantor' => $this->alamat_kantor,
            'no_hp' => $this->no_hp,
        ];
    }
}
