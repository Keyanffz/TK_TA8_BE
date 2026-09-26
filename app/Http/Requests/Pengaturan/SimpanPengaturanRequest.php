<?php

namespace App\Http\Requests\Pengaturan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `{ items: { "profil.visi": "…", "landing.program": [ … ] } }`. Validasi per kunci dijalankan
 * `PengaturanService` karena kunci pengaturan sendiri mengandung titik.
 */
class SimpanPengaturanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Objek berkunci lengkap, misalnya `{ "profil.visi": "…", "keuangan.tanggal_jatuh_tempo": 10 }`.
             *
             * @var array<string, mixed>
             */
            'items' => ['required', 'array', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function items(): array
    {
        $items = $this->validated('items');

        return is_array($items) ? $items : [];
    }
}
