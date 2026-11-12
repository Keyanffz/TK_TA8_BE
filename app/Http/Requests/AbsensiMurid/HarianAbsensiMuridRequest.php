<?php

namespace App\Http\Requests\AbsensiMurid;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class HarianAbsensiMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `YYYY-MM-DD`, bawaan hari ini. */
            'tanggal' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function tanggal(): Carbon
    {
        return $this->filled('tanggal') ? Carbon::createFromFormat('Y-m-d', $this->string('tanggal')->toString())->startOfDay() : today();
    }
}
