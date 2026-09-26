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
