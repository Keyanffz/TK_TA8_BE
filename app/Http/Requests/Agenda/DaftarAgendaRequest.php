<?php

namespace App\Http\Requests\Agenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * `?bulan=YYYY-MM`, bawaan bulan ini. Dipakai `GET /agenda` dan `GET /public/agenda`.
 */
class DaftarAgendaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bulan' => ['sometimes', 'date_format:Y-m'],
        ];
    }

    public function bulan(): Carbon
    {
        return $this->filled('bulan')
            ? Carbon::createFromFormat('Y-m-d', $this->string('bulan')->toString().'-01')->startOfDay()
            : now()->startOfMonth();
    }
}
