<?php

namespace App\Http\Requests\Murid;

use App\Enums\StatusMurid;
use App\Services\StatusMuridService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * `PATCH /murid/{id}/status`. `tanggal` = tanggal lulus, pindah, keluar, atau kembali aktif. `tahun_lulus` wajib
 * untuk status lulus; `sekolah_tujuan` hanya untuk status pindah.
 */
class UbahStatusMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lulus = StatusMurid::Lulus->value;

        return [
            'status' => ['required', Rule::enum(StatusMurid::class)],
            'tanggal' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'tahun_lulus' => [
                'nullable', 'required_if:status,'.$lulus, 'prohibited_unless:status,'.$lulus,
                'integer', 'min:'.StatusMuridService::TAHUN_LULUS_PALING_AWAL, 'max:'.now()->year,
            ],
            'sekolah_tujuan' => ['nullable', 'prohibited_unless:status,'.StatusMurid::Pindah->value, 'string', 'max:150'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tanggal.before_or_equal' => 'Tanggal status tidak boleh setelah hari ini.',
            'tahun_lulus.required_if' => 'Tahun lulus wajib diisi untuk murid yang lulus.',
            'tahun_lulus.prohibited_unless' => 'Tahun lulus hanya diisi untuk murid yang lulus.',
            'sekolah_tujuan.prohibited_unless' => 'Sekolah tujuan hanya diisi untuk murid yang pindah.',
        ];
    }

    /**
     * @return array{status: StatusMurid, tanggal: Carbon, tahun_lulus: int|null, sekolah_tujuan: string|null, keterangan: string|null}
     */
    public function perubahan(): array
    {
        return [
            'status' => $this->enum('status', StatusMurid::class) ?? throw new LogicException('Status sudah divalidasi wajib ada.'),
            'tanggal' => Carbon::parse($this->string('tanggal')->toString())->startOfDay(),
            'tahun_lulus' => $this->filled('tahun_lulus') ? $this->integer('tahun_lulus') : null,
            'sekolah_tujuan' => $this->filled('sekolah_tujuan') ? $this->string('sekolah_tujuan')->trim()->toString() : null,
            'keterangan' => $this->filled('keterangan') ? $this->string('keterangan')->trim()->toString() : null,
        ];
    }
}
