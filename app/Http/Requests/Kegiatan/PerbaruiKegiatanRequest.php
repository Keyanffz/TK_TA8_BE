<?php

namespace App\Http\Requests\Kegiatan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /kegiatan/{id}`: tanggal, tema, judul, dan deskripsi. Kelas tidak bisa diganti dan foto dikelola lewat
 * endpoint foto; mengirim `kelas_id` atau `foto` dibalas 422.
 */
class PerbaruiKegiatanRequest extends FormRequest
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
            /** @ignoreParam */
            'kelas_id' => ['prohibited'],
            /** @ignoreParam */
            'foto' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kelas_id.prohibited' => 'Kelas kegiatan tidak bisa diganti. Hapus kegiatan ini lalu catat ulang di kelas yang benar.',
            'foto.prohibited' => 'Foto ditambahkan lewat POST /kegiatan/{id}/foto.',
        ];
    }
}
