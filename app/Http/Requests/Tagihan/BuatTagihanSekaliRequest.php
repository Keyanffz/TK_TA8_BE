<?php

namespace App\Http\Requests\Tagihan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * `POST /tagihan`: tagihan sekali bayar untuk daftar murid (`murid_ids`) atau seluruh murid aktif satu kelas
 * (`kelas_id`), salah satu saja.
 */
class BuatTagihanSekaliRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jenis_tagihan_id' => ['required', 'integer', Rule::exists('jenis_tagihan', 'id')],
            'murid_ids' => ['required_without:kelas_id', 'prohibits:kelas_id', 'array', 'min:1'],
            'murid_ids.*' => ['integer', 'distinct', Rule::exists('murid', 'id')->withoutTrashed()],
            'kelas_id' => ['required_without:murid_ids', 'integer', Rule::exists('kelas', 'id')],
            'jatuh_tempo' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /**
     * @return list<int>|null null jika sasaran dipilih lewat `kelas_id`
     */
    public function muridIds(): ?array
    {
        return $this->has('murid_ids') ? array_values(array_map('intval', (array) $this->input('murid_ids'))) : null;
    }

    public function jatuhTempo(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('jatuh_tempo')->toString())->startOfDay();
    }
}
