<?php

namespace App\Http\Requests\Murid;

use App\Services\NisMuridService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /murid/nis/batch/pratinjau` dan `POST /murid/nis/batch`: NIS yang diketik di tabel web. Isi tiap baris
 * diperiksa `NisMuridService` dan hasilnya dilaporkan per baris, bukan sebagai error validasi.
 *
 * @phpstan-import-type BarisNis from NisMuridService
 */
class BatchNisMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.NisMuridService::MAKSIMAL_BARIS],
            'items.*.murid_id' => ['required', 'integer', 'distinct'],
            'items.*.nis' => ['present', 'nullable', 'string', 'max:50'],
        ];
    }

    /**
     * `nomor` = urutan item mulai 1.
     *
     * @return list<BarisNis>
     */
    public function baris(): array
    {
        /** @var list<array{murid_id: int|string, nis: string|null}> $items */
        $items = $this->validated('items');

        return array_map(fn (array $item, int $indeks): array => [
            'nomor' => $indeks + 1,
            'murid_id' => (int) $item['murid_id'],
            'nama_lengkap' => null,
            'nis' => $item['nis'],
        ], $items, array_keys($items));
    }
}
