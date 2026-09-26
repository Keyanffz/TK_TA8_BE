<?php

namespace App\Http\Requests\Kelas;

use App\Enums\StatusAkun;
use App\Enums\Tingkat;
use App\Models\Guru;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dipakai `POST /kelas` dan `PUT /kelas/{id}`. Nama kelas unik dalam satu tahun ajaran.
 */
class SimpanKelasRequest extends FormRequest
{
    private const KAPASITAS_MAKSIMAL = 50;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tahun_ajaran_id' => ['required', 'integer', Rule::exists('tahun_ajaran', 'id')],
            'nama' => [
                'required', 'string', 'max:50',
                Rule::unique('kelas', 'nama')->where('tahun_ajaran_id', $this->integer('tahun_ajaran_id'))->ignore($this->route('id')),
            ],
            'tingkat' => ['required', Rule::enum(Tingkat::class)],
            'wali_kelas_id' => ['nullable', 'integer', $this->guruAktif()],
            'guru_pendamping_id' => ['nullable', 'integer', 'different:wali_kelas_id', $this->guruAktif()],
            'kapasitas' => ['sometimes', 'integer', 'min:1', 'max:'.self::KAPASITAS_MAKSIMAL],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.unique' => 'Nama kelas sudah dipakai di tahun ajaran ini.',
            'guru_pendamping_id.different' => 'Guru pendamping harus berbeda dengan wali kelas.',
        ];
    }

    /**
     * Guru yang belum disetujui atau sudah nonaktif tidak bisa login, jadi tidak bisa mengampu kelas.
     */
    private function guruAktif(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $aktif = Guru::query()->whereKey($value)
                ->whereHas('user', fn ($user) => $user->where('status', StatusAkun::Aktif))
                ->exists();

            if (! $aktif) {
                $fail('Guru tidak ditemukan atau akunnya belum aktif.');
            }
        };
    }
}
