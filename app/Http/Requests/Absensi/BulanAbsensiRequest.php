<?php

namespace App\Http\Requests\Absensi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Dipakai rekap dan ekspornya; riwayat memakai turunannya. `bulan` bawaan bulan berjalan.
 */
class BulanAbsensiRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Bulan `YYYY-MM`, bawaan bulan berjalan. */
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
