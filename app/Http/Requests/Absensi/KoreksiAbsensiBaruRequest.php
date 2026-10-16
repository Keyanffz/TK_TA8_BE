<?php

namespace App\Http\Requests\Absensi;

use App\Enums\StatusAbsensi;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * `POST /absensi/koreksi`: absen masuk untuk hari yang belum punya baris. Tidak hadir tidak bisa dibuat di sini;
 * baris itu dibuat scheduler.
 */
class KoreksiAbsensiBaruRequest extends KoreksiAbsensiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Peserta absensi: guru aktif atau Kepala Sekolah. */
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            /** Tanggal `YYYY-MM-DD`. */
            'tanggal' => ['required', 'date_format:Y-m-d'],
            ...parent::rules(),
            'status' => ['required', Rule::enum(StatusAbsensi::class)->only([StatusAbsensi::Hadir, StatusAbsensi::Terlambat])],
        ];
    }

    public function tanggal(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('tanggal')->toString())->startOfDay();
    }
}
