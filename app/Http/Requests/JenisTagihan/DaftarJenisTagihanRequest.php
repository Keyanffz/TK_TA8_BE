<?php

namespace App\Http\Requests\JenisTagihan;

use App\Enums\PeriodeTagihan;
use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarJenisTagihanRequest extends FormRequest
{
    use MemvalidasiDaftar;

    protected function prepareForValidation(): void
    {
        $this->normalkanFilterBoolean(['is_aktif']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['nama', 'created_at']),
            'filter' => ['sometimes', 'array:tahun_ajaran_id,periode,is_aktif'],
            'filter.tahun_ajaran_id' => ['sometimes', 'integer'],
            'filter.periode' => ['sometimes', Rule::enum(PeriodeTagihan::class)],
            'filter.is_aktif' => ['sometimes', 'boolean'],
        ];
    }
}
