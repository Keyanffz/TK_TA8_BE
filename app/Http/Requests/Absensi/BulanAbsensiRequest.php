<?php

namespace App\Http\Requests\Absensi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Dipakai rekap dan ekspornya; riwayat memakai turunannya. Rentangnya `jumlah_bulan` bulan yang berakhir di
 * `bulan`, bawaan satu bulan berjalan.
 */
class BulanAbsensiRequest extends FormRequest
{
    public const JUMLAH_BULAN_MAKSIMAL = 3;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Bulan terakhir rentang, `YYYY-MM`, bawaan bulan berjalan. */
            'bulan' => ['sometimes', 'date_format:Y-m'],
            /** Jumlah bulan sampai `bulan`, 1–3, bawaan 1. Contoh `bulan=2026-10&jumlah_bulan=3` = Agustus sampai Oktober 2026. */
            'jumlah_bulan' => ['sometimes', 'integer', 'between:1,'.self::JUMLAH_BULAN_MAKSIMAL],
        ];
    }

    /**
     * Awal bulan pertama dan akhir bulan terakhir rentang.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function rentang(): array
    {
        $bulan = $this->filled('bulan')
            ? Carbon::createFromFormat('Y-m-d', $this->string('bulan')->toString().'-01')->startOfDay()
            : now()->startOfMonth();
        $jumlah = $this->filled('jumlah_bulan') ? $this->integer('jumlah_bulan') : 1;

        return [$bulan->copy()->subMonthsNoOverflow($jumlah - 1), $bulan->copy()->endOfMonth()];
    }
}
