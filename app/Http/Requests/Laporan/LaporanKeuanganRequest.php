<?php

namespace App\Http\Requests\Laporan;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Dipakai `GET /laporan/keuangan` dan ekspornya. Rentang dibatasi dua tahun supaya rincian per bulan tetap
 * wajar.
 */
class LaporanKeuanganRequest extends FormRequest
{
    private const HARI_MAKSIMAL = 731;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dari' => ['required', 'date_format:Y-m-d'],
            'sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:dari', $this->rentangWajar()],
            'kelas_id' => ['sometimes', 'nullable', 'integer', Rule::exists('kelas', 'id')],
        ];
    }

    private function rentangWajar(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $dari = Carbon::canBeCreatedFromFormat((string) $this->input('dari'), 'Y-m-d') ? Carbon::parse((string) $this->input('dari')) : null;

            if ($dari !== null && Carbon::parse((string) $value)->gt($dari->copy()->addDays(self::HARI_MAKSIMAL))) {
                $fail('Rentang laporan paling panjang dua tahun.');
            }
        };
    }

    public function dari(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('dari')->toString())->startOfDay();
    }

    public function sampai(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('sampai')->toString())->startOfDay();
    }

    public function kelasId(): ?int
    {
        return $this->filled('kelas_id') ? $this->integer('kelas_id') : null;
    }
}
