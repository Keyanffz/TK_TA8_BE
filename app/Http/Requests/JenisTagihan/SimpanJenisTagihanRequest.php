<?php

namespace App\Http\Requests\JenisTagihan;

use App\Enums\PeriodeTagihan;
use App\Enums\Tingkat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dipakai `POST /jenis-tagihan` dan `PUT /jenis-tagihan/{id}`. `tingkat` kosong berarti berlaku untuk
 * semua tingkat.
 */
class SimpanJenisTagihanRequest extends FormRequest
{
    /** Batas atas nominal supaya salah ketik (kelebihan nol) tidak lolos. */
    private const NOMINAL_MAKSIMAL = 100_000_000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tahun_ajaran_id' => ['required', 'integer', Rule::exists('tahun_ajaran', 'id')],
            'nama' => ['required', 'string', 'max:100'],
            'deskripsi' => ['nullable', 'string', 'max:500'],
            'nominal' => ['required', 'integer', 'min:1', 'max:'.self::NOMINAL_MAKSIMAL],
            'periode' => ['required', Rule::enum(PeriodeTagihan::class)],
            'tingkat' => ['nullable', Rule::enum(Tingkat::class)],
            'is_aktif' => ['sometimes', 'boolean'],
        ];
    }
}
