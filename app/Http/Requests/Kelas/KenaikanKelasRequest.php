<?php

namespace App\Http\Requests\Kelas;

use App\Enums\StatusKelasMurid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /kelas/kenaikan`: murid naik atau tinggal kelas mendapat kelas di tahun ajaran tujuan; murid lulus tidak.
 */
class KenaikanKelasRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tahun_ajaran_tujuan_id' => ['required', 'integer', Rule::exists('tahun_ajaran', 'id')],
            'penempatan' => ['required', 'array', 'min:1'],
            'penempatan.*.murid_id' => ['required', 'integer', 'distinct', Rule::exists('murid', 'id')->withoutTrashed()],
            'penempatan.*.status' => [
                'required',
                Rule::enum(StatusKelasMurid::class)->only([StatusKelasMurid::Naik, StatusKelasMurid::Tinggal, StatusKelasMurid::Lulus]),
            ],
            'penempatan.*.kelas_tujuan_id' => [
                'nullable', 'integer', Rule::exists('kelas', 'id'),
                'required_unless:penempatan.*.status,'.StatusKelasMurid::Lulus->value,
                'prohibited_if:penempatan.*.status,'.StatusKelasMurid::Lulus->value,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'penempatan.*.kelas_tujuan_id.required_unless' => 'Pilih kelas tujuan untuk murid yang naik atau tinggal kelas.',
            'penempatan.*.kelas_tujuan_id.prohibited_if' => 'Murid yang lulus tidak ditempatkan di kelas tujuan.',
        ];
    }

    /**
     * @return list<array{murid_id: int, status: StatusKelasMurid, kelas_tujuan_id: int|null}>
     */
    public function penempatan(): array
    {
        return array_values(array_map(fn (array $baris): array => [
            'murid_id' => (int) $baris['murid_id'],
            'status' => StatusKelasMurid::from($baris['status']),
            'kelas_tujuan_id' => isset($baris['kelas_tujuan_id']) ? (int) $baris['kelas_tujuan_id'] : null,
        ], (array) $this->validated('penempatan')));
    }
}
