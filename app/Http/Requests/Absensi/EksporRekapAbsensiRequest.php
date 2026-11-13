<?php

namespace App\Http\Requests\Absensi;

use Illuminate\Validation\Rule;

class EksporRekapAbsensiRequest extends BulanAbsensiRequest
{
    public const FORMAT = ['xlsx', 'pdf'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** `xlsx` (bawaan) atau `pdf`. */
            'format' => ['sometimes', Rule::in(self::FORMAT)],
        ];
    }

    public function formatFile(): string
    {
        return $this->string('format', self::FORMAT[0])->toString();
    }
}
