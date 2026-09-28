<?php

namespace App\Http\Requests\Kegiatan;

use App\Enums\Role;
use App\Models\Kelas;
use App\Models\User;
use App\Services\KegiatanKelasService;
use App\Services\MediaService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * `POST /kegiatan` (multipart: data + `foto[]` maksimal 10). Guru hanya mencatat kegiatan untuk kelas yang dia
 * ampu di tahun ajaran aktif. `PUT /kegiatan/{id}` memakai `PerbaruiKegiatanRequest`.
 */
class SimpanKegiatanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'tema' => ['nullable', 'string', 'max:100'],
            'judul' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string', 'max:5000'],
            'kelas_id' => ['required', 'integer', 'exists:kelas,id', $this->kelasDiampu(...)],
            'foto' => ['sometimes', 'array', 'max:'.KegiatanKelasService::MAKSIMAL_FOTO_PER_UNGGAHAN],
            'foto.*' => ['required', ...MediaService::aturanGambar()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dataKegiatan(): array
    {
        return collect($this->validated())->except('foto')->all();
    }

    /**
     * @return list<UploadedFile>
     */
    public function foto(): array
    {
        return array_values(array_filter((array) $this->file('foto', []), fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    private function kelasDiampu(string $attribute, mixed $value, Closure $fail): void
    {
        $user = $this->user();

        if ($user instanceof User && $user->role === Role::Guru && ! Kelas::query()->diampuOleh($user)->whereKey($value)->exists()) {
            $fail('Anda hanya bisa mencatat kegiatan untuk kelas yang Anda ampu.');
        }
    }
}
