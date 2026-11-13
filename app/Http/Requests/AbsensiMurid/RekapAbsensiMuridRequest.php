<?php

namespace App\Http\Requests\AbsensiMurid;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Dipakai rekap dan ekspornya; `format` hanya berpengaruh di ekspor.
 */
class RekapAbsensiMuridRequest extends FormRequest
{
    public const FORMAT = ['xlsx', 'pdf'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `YYYY-MM`, bawaan bulan berjalan. */
            'bulan' => ['sometimes', 'date_format:Y-m'],
            /** Ekspor saja: `xlsx` (bawaan) atau `pdf`. */
            'format' => ['sometimes', Rule::in(self::FORMAT)],
        ];
    }

    public function bulan(): Carbon
    {
        return $this->filled('bulan')
            ? Carbon::createFromFormat('Y-m-d', $this->string('bulan')->toString().'-01')->startOfDay()
            : now()->startOfMonth();
    }

    public function formatFile(): string
    {
        return $this->string('format', self::FORMAT[0])->toString();
    }
}
