<?php

namespace App\Http\Requests\Keringanan;

use App\Enums\TipeKeringanan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dipakai `POST /keringanan` dan `PUT /keringanan/{id}`. `nilai` berupa persen 1–100 untuk tipe `persen`,
 * atau rupiah untuk tipe `nominal`.
 */
class SimpanKeringananRequest extends FormRequest
{
    private const PERSEN_MAKSIMAL = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'murid_id' => ['required', 'integer', Rule::exists('murid', 'id')->withoutTrashed()],
            'jenis_tagihan_id' => ['required', 'integer', Rule::exists('jenis_tagihan', 'id')],
            'tipe' => ['required', Rule::enum(TipeKeringanan::class)],
            'nilai' => [
                'required', 'integer', 'min:1',
                Rule::when($this->input('tipe') === TipeKeringanan::Persen->value, ['max:'.self::PERSEN_MAKSIMAL]),
            ],
            'alasan' => ['required', 'string', 'max:255'],
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:berlaku_mulai'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nilai.max' => 'Keringanan persen paling besar 100.',
        ];
    }
}
