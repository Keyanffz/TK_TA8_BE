<?php

namespace App\Http\Requests\Tagihan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /tagihan/{id}`: field yang tidak dikirim tidak berubah. Potongan tidak boleh melebihi nominal tagihan,
 * dan jatuh tempo yang diubah tidak boleh sebelum hari ini (keduanya dicek terhadap data tagihan di service).
 */
class PerbaruiTagihanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jatuh_tempo' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'potongan' => ['sometimes', 'required', 'integer', 'min:0'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{jatuh_tempo?: string, potongan?: int, catatan?: string|null}
     */
    public function dataTagihan(): array
    {
        $data = [];

        if ($this->has('jatuh_tempo')) {
            $data['jatuh_tempo'] = $this->string('jatuh_tempo')->toString();
        }
        if ($this->has('potongan')) {
            $data['potongan'] = $this->integer('potongan');
        }
        if ($this->exists('catatan')) {
            $catatan = trim($this->string('catatan')->toString());
            $data['catatan'] = $catatan === '' ? null : $catatan;
        }

        return $data;
    }
}
