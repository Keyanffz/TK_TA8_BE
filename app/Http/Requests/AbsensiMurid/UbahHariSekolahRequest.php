<?php

namespace App\Http\Requests\AbsensiMurid;

use Illuminate\Foundation\Http\FormRequest;

class UbahHariSekolahRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Nomor hari ISO, 1 = Senin sampai 7 = Minggu; minimal satu hari. */
            'hari_sekolah' => ['required', 'array', 'list', 'min:1', 'max:7'],
            'hari_sekolah.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hari_sekolah.required' => 'Pilih minimal satu hari sekolah.',
            'hari_sekolah.min' => 'Pilih minimal satu hari sekolah.',
        ];
    }

    /**
     * @return list<int>
     */
    public function hari(): array
    {
        /** @var list<int|string> $hari */
        $hari = $this->validated('hari_sekolah');
        $hari = array_map(intval(...), $hari);
        sort($hari);

        return $hari;
    }
}
