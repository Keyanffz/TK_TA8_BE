<?php

namespace App\Http\Requests\Rapor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /rapor/{id}`. Elemen yang tidak dikirim di `detail` tidak berubah.
 */
class IsiRaporRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tinggi_badan' => ['nullable', 'numeric', 'between:50,200', 'decimal:0,1'],
            'berat_badan' => ['nullable', 'numeric', 'between:5,80', 'decimal:0,1'],
            'catatan_guru' => ['nullable', 'string', 'max:3000'],
            'detail' => ['sometimes', 'array'],
            'detail.*.elemen_penilaian_id' => ['required', 'integer', 'distinct'],
            'detail.*.deskripsi' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dataRapor(): array
    {
        return collect($this->validated())->only(['tinggi_badan', 'berat_badan', 'catatan_guru'])->all();
    }

    /**
     * @return array<int, string|null> deskripsi per `elemen_penilaian_id`
     */
    public function deskripsiPerElemen(): array
    {
        /** @var list<array{elemen_penilaian_id: int|string, deskripsi?: string|null}> $detail */
        $detail = $this->validated('detail', []);

        return collect($detail)->mapWithKeys(fn (array $baris): array => [(int) $baris['elemen_penilaian_id'] => $baris['deskripsi'] ?? null])->all();
    }
}
