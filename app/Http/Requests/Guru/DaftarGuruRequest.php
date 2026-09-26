<?php

namespace App\Http\Requests\Guru;

use App\Enums\StatusAkun;
use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarGuruRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['nama', 'created_at']),
            'filter' => ['sometimes', 'array:status'],
            'filter.status' => ['sometimes', Rule::enum(StatusAkun::class)],
        ];
    }
}
