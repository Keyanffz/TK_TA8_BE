<?php

namespace App\Http\Requests\Rapor;

use App\Enums\StatusRapor;
use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarRaporRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['updated_at', 'diajukan_at', 'created_at']),
            'filter' => ['sometimes', 'array:kelas_id,semester,status,tahun_ajaran_id,murid_id'],
            'filter.kelas_id' => ['sometimes', 'integer'],
            'filter.semester' => ['sometimes', 'integer', 'in:1,2'],
            'filter.status' => ['sometimes', Rule::enum(StatusRapor::class)],
            'filter.tahun_ajaran_id' => ['sometimes', 'integer'],
            'filter.murid_id' => ['sometimes', 'integer'],
        ];
    }
}
