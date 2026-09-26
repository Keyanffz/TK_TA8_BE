<?php

namespace App\Http\Requests\Wali;

use App\Enums\Hubungan;
use App\Models\Murid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TautkanAnakRequest extends FormRequest
{
    /**
     * Kode sering diketik dengan huruf kecil atau spasi/tanda hubung dari pesan WhatsApp.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('kode'))) {
            $this->merge(['kode' => strtoupper((string) preg_replace('/[\s-]+/', '', $this->input('kode')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'size:'.Murid::PANJANG_KODE_TAUTAN],
            'tanggal_lahir' => ['required', 'date_format:Y-m-d'],
            'hubungan' => ['required', Rule::enum(Hubungan::class)],
        ];
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
