<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Parameter bersama endpoint list (A7): `page`, `per_page` (default 15, maksimal 100), `search`, `sort`.
 * Aturan `sort` dan `filter[...]` ditulis di FormRequest juga supaya muncul di dokumentasi OpenAPI;
 * spatie/laravel-query-builder tetap menjadi penjaga terakhir.
 */
trait MemvalidasiDaftar
{
    private const PER_HALAMAN_BAWAAN = 15;

    private const PER_HALAMAN_MAKSIMAL = 100;

    /**
     * @return array<string, array<int, string>>
     */
    protected function aturanDaftar(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::PER_HALAMAN_MAKSIMAL],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  list<string>  $kolom  kolom yang boleh diurutkan; awalan `-` untuk urutan menurun
     * @return array<string, array<int, mixed>>
     */
    protected function aturanUrutan(array $kolom): array
    {
        $pilihan = [...$kolom, ...array_map(fn (string $k): string => '-'.$k, $kolom)];

        return ['sort' => ['sometimes', 'string', Rule::in($pilihan)]];
    }

    public function perHalaman(): int
    {
        return $this->integer('per_page', self::PER_HALAMAN_BAWAAN);
    }

    public function kataCari(): ?string
    {
        $kata = trim($this->string('search')->toString());

        return $kata === '' ? null : $kata;
    }
}
