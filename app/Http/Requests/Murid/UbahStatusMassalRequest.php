<?php

namespace App\Http\Requests\Murid;

use Illuminate\Validation\Rule;

/**
 * `POST /murid/status-massal`: isian yang sama dengan ubah status satu murid, untuk daftar murid (`murid_ids`) atau
 * semua murid aktif satu kelas (`kelas_id`, dipakai untuk meluluskan satu kelas), salah satu saja.
 */
class UbahStatusMassalRequest extends UbahStatusMuridRequest
{
    private const MAKSIMAL_MURID = 500;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'murid_ids' => ['required_without:kelas_id', 'prohibits:kelas_id', 'array', 'min:1', 'max:'.self::MAKSIMAL_MURID],
            'murid_ids.*' => ['integer', 'distinct', Rule::exists('murid', 'id')->withoutTrashed()],
            'kelas_id' => ['required_without:murid_ids', 'integer', Rule::exists('kelas', 'id')],
        ];
    }

    /**
     * @return list<int>|null null jika sasaran dipilih lewat `kelas_id`
     */
    public function muridIds(): ?array
    {
        return $this->has('murid_ids') ? array_values(array_map('intval', (array) $this->input('murid_ids'))) : null;
    }
}
