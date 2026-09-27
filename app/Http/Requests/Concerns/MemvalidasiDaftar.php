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

    /**
     * Filter boolean bertipe `boolean` di OpenAPI, jadi klien boleh mengirim `true`/`false` selain `1`/`0`.
     * Aturan `boolean` Laravel tidak menerima teks `true`/`false`, jadi nilainya diubah ke `1`/`0` sebelum
     * validasi. Dipanggil dari `prepareForValidation()`.
     *
     * @param  list<string>  $nama  nama filter boolean, misalnya `dibaca` untuk `filter[dibaca]`
     */
    protected function normalkanFilterBoolean(array $nama): void
    {
        $filter = $this->input('filter');

        if (! is_array($filter)) {
            return;
        }

        foreach ($nama as $satu) {
            $nilai = is_string($filter[$satu] ?? null) ? strtolower($filter[$satu]) : null;

            if ($nilai === 'true' || $nilai === 'false') {
                $filter[$satu] = $nilai === 'true' ? '1' : '0';
            }
        }

        $this->merge(['filter' => $filter]);
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
