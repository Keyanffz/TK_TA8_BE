<?php

namespace App\Http\Requests\Murid;

use App\Enums\StatusMurid;
use App\Enums\Tingkat;
use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarMuridRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['nama', 'nis', 'created_at']),
            'filter' => ['sometimes', 'array:kelas_id,status,tingkat'],
            'filter.kelas_id' => ['sometimes', 'integer'],
            'filter.status' => ['sometimes', Rule::enum(StatusMurid::class)],
            'filter.tingkat' => ['sometimes', Rule::enum(Tingkat::class)],
        ];
    }
}
