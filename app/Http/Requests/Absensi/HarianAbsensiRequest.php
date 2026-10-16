<?php

namespace App\Http\Requests\Absensi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class HarianAbsensiRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Tanggal `YYYY-MM-DD`, bawaan hari ini. */
            'tanggal' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['tanggal.before_or_equal' => 'Tanggal tidak boleh setelah hari ini.'];
    }

    public function tanggal(): Carbon
    {
        return $this->filled('tanggal')
            ? Carbon::createFromFormat('Y-m-d', $this->string('tanggal')->toString())->startOfDay()
            : today();
    }
}
