<?php

namespace App\Http\Requests\AbsensiMurid;

use App\Enums\StatusAbsensiMurid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SimpanAbsensiMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `YYYY-MM-DD`. */
            'tanggal' => ['required', 'date_format:Y-m-d'],
            /** Semua murid aktif kelas itu, masing-masing sekali. */
            'absensi' => ['required', 'array', 'list'],
            'absensi.*.murid_id' => ['required', 'integer', 'distinct'],
            'absensi.*.status' => ['required', Rule::enum(StatusAbsensiMurid::class)],
            'absensi.*.keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'absensi.*.murid_id.distinct' => 'Satu murid hanya boleh muncul sekali.',
            'absensi.*.status.required' => 'Pilih status untuk setiap murid.',
        ];
    }

    public function tanggal(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('tanggal')->toString())->startOfDay();
    }

    /**
     * @return list<array{murid_id: int, status: StatusAbsensiMurid, keterangan: string|null}>
     */
    public function absensi(): array
    {
        /** @var list<array{murid_id: int|string, status: string, keterangan?: string|null}> $baris */
        $baris = $this->validated('absensi');

        return array_map(fn (array $satu): array => [
            'murid_id' => (int) $satu['murid_id'],
            'status' => StatusAbsensiMurid::from($satu['status']),
            'keterangan' => isset($satu['keterangan']) && trim($satu['keterangan']) !== '' ? trim($satu['keterangan']) : null,
        ], $baris);
    }
}
