<?php

namespace App\Http\Requests\Pendaftaran;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StatusPendaftaranPublikRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('kode'))) {
            $this->merge(['kode' => strtoupper(trim($this->query('kode')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'max:20'],
            'tanggal_lahir' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['kode' => 'kode pendaftaran'];
    }

    public function tanggalLahir(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('tanggal_lahir')->toString())->startOfDay();
    }
}
