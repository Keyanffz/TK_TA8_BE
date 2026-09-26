<?php

namespace App\Http\Requests\Kelas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TempatkanMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'murid_ids' => ['required', 'array', 'min:1'],
            'murid_ids.*' => ['integer', 'distinct', Rule::exists('murid', 'id')->withoutTrashed()],
        ];
    }

    /**
     * @return list<int>
     */
    public function muridIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('murid_ids')));
    }
}
