<?php

namespace App\Http\Requests\Tagihan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class GenerateTagihanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'periode' => ['required', 'date_format:Y-m'],
        ];
    }

    public function periode(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('periode')->toString().'-01')->startOfDay();
    }
}
