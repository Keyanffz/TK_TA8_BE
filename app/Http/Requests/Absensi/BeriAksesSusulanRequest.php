<?php

namespace App\Http\Requests\Absensi;

use App\Enums\JenisAbsensi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use LogicException;

class BeriAksesSusulanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Peserta absensi: guru aktif. */
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            /** `YYYY-MM-DD`: hari ini atau hari kerja terakhir sebelum hari ini. */
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'jenis' => ['required', Rule::enum(JenisAbsensi::class)],
            /** Alasan akses diberikan, misalnya "Wifi sekolah mati sejak pagi." */
            'catatan' => ['required', 'string', 'max:500'],
        ];
    }

    public function tanggal(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('tanggal')->toString())->startOfDay();
    }

    public function jenis(): JenisAbsensi
    {
        return $this->enum('jenis', JenisAbsensi::class) ?? throw new LogicException('Jenis absensi sudah divalidasi wajib ada.');
    }

    public function catatan(): string
    {
        return $this->string('catatan')->trim()->toString();
    }
}
