<?php

namespace App\Http\Requests\LogAktivitas;

use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarLogAktivitasRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /** Nilai `log_name` yang ditulis aplikasi (B7). */
    public const JENIS = ['akun', 'guru', 'wali', 'tagihan', 'pembayaran', 'rapor', 'ppdb', 'pengaturan'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...collect($this->aturanDaftar())->only(['page', 'per_page'])->all(),
            'filter' => ['sometimes', 'array:user_id,jenis,tanggal'],
            'filter.user_id' => ['sometimes', 'integer'],
            'filter.jenis' => ['sometimes', Rule::in(self::JENIS)],
            'filter.tanggal' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
