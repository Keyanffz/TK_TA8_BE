<?php

namespace App\Http\Requests\Pengumuman;

use App\Enums\Role;
use App\Enums\TargetPengumuman;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * `POST /pengumuman` dan `PUT /pengumuman/{id}` (B6.10). Kepala Sekolah bebas memilih target; guru hanya
 * `kelas` (kelas yang dia ampu) atau `murid` (murid di kelasnya). `is_publik` hanya untuk target `semua`.
 * `publish: false` menyimpan sebagai draft.
 */
class SimpanPengumumanRequest extends FormRequest
{
    private const TARGET_GURU = [TargetPengumuman::Kelas, TargetPengumuman::Murid];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->guru() ? Rule::enum(TargetPengumuman::class)->only(self::TARGET_GURU) : Rule::enum(TargetPengumuman::class);

        return [
            'judul' => ['required', 'string', 'max:255'],
            'isi' => ['required', 'string', 'max:50000'],
            'target' => ['required', $target],
            'kelas_ids' => ['required_if:target,'.TargetPengumuman::Kelas->value, 'prohibited_unless:target,'.TargetPengumuman::Kelas->value, 'array'],
            'kelas_ids.*' => ['integer', 'distinct', 'exists:kelas,id', $this->kelasDiampu(...)],
            'murid_ids' => ['required_if:target,'.TargetPengumuman::Murid->value, 'prohibited_unless:target,'.TargetPengumuman::Murid->value, 'array'],
            'murid_ids.*' => ['integer', 'distinct', 'exists:murid,id', $this->muridDiKelasnya(...)],
            'is_publik' => ['sometimes', 'boolean', $this->publikHanyaUntukSemua(...)],
            'is_pinned' => ['sometimes', 'boolean'],
            'publish' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target.enum' => $this->guru()
                ? 'Guru hanya bisa membuat pengumuman untuk kelas yang diampu atau murid di kelasnya.'
                : 'Target pengumuman tidak dikenal.',
            'kelas_ids.required_if' => 'Pilih minimal satu kelas.',
            'murid_ids.required_if' => 'Pilih minimal satu murid.',
            'kelas_ids.prohibited_unless' => 'Daftar kelas hanya diisi untuk target kelas.',
            'murid_ids.prohibited_unless' => 'Daftar murid hanya diisi untuk target murid.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dataPengumuman(): array
    {
        return collect($this->validated())->only(['judul', 'isi', 'target', 'is_publik', 'is_pinned'])->all();
    }

    public function target(): TargetPengumuman
    {
        return $this->enum('target', TargetPengumuman::class) ?? throw new LogicException('Target sudah divalidasi wajib ada.');
    }

    /**
     * @return list<int>
     */
    public function kelasIds(): array
    {
        return array_map(intval(...), $this->validated('kelas_ids', []));
    }

    /**
     * @return list<int>
     */
    public function muridIds(): array
    {
        return array_map(intval(...), $this->validated('murid_ids', []));
    }

    private function guru(): bool
    {
        return $this->user()?->role === Role::Guru;
    }

    private function kelasDiampu(string $attribute, mixed $value, Closure $fail): void
    {
        $user = $this->user();

        if ($this->guru() && $user instanceof User && ! Kelas::query()->diampuOleh($user)->whereKey($value)->exists()) {
            $fail('Anda hanya bisa memilih kelas yang Anda ampu.');
        }
    }

    private function muridDiKelasnya(string $attribute, mixed $value, Closure $fail): void
    {
        $user = $this->user();

        if ($this->guru() && $user instanceof User && ! Murid::query()->visibleTo($user)->whereKey($value)->exists()) {
            $fail('Anda hanya bisa memilih murid di kelas yang Anda ampu.');
        }
    }

    private function publikHanyaUntukSemua(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->boolean('is_publik') && $this->input('target') !== TargetPengumuman::Semua->value) {
            $fail('Hanya pengumuman untuk semua yang bisa ditampilkan di website.');
        }
    }
}
