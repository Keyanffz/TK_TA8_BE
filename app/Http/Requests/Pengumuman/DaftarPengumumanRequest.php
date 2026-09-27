<?php

namespace App\Http\Requests\Pengumuman;

use App\Enums\TargetPengumuman;
use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarPengumumanRequest extends FormRequest
{
    use MemvalidasiDaftar;

    protected function prepareForValidation(): void
    {
        $this->normalkanFilterBoolean(['terbit']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            'filter' => ['sometimes', 'array:target,terbit'],
            'filter.target' => ['sometimes', Rule::enum(TargetPengumuman::class)],
            'filter.terbit' => ['sometimes', 'boolean'],
        ];
    }
}
