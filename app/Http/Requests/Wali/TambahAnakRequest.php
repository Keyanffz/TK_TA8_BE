<?php

namespace App\Http\Requests\Wali;

use App\Enums\Hubungan;
use App\Http\Requests\Auth\LoginWaliRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TambahAnakRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nis'))) {
            $this->merge(['nis' => LoginWaliRequest::normalkanUsername($this->input('nis'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nis' => ['required', 'string', 'max:20'],
            'tanggal_lahir' => ['required', 'date_format:Y-m-d'],
            'hubungan' => ['required', Rule::enum(Hubungan::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nis' => 'NIS'];
    }

    public function tanggalLahir(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('tanggal_lahir')->toString())->startOfDay();
    }

    public function hubungan(): Hubungan
    {
        return Hubungan::from($this->string('hubungan')->toString());
    }
}
