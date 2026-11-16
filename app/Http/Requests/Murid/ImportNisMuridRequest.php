<?php

namespace App\Http\Requests\Murid;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * `POST /murid/nis/import/pratinjau` dan `POST /murid/nis/import` (multipart): file .xlsx dari template, maksimal
 * 2 MB. CSV tidak diterima karena Excel membuang angka nol di depan NIS saat membuka CSV.
 */
class ImportNisMuridRequest extends FormRequest
{
    private const UKURAN_MAKSIMAL_KB = 2048;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::UKURAN_MAKSIMAL_KB, 'mimes:xlsx', 'extensions:xlsx'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'File harus berformat .xlsx.',
            'file.extensions' => 'File harus berformat .xlsx.',
            'file.max' => 'Ukuran file maksimal 2 MB. Bagi data menjadi beberapa file.',
        ];
    }

    public function berkas(): UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : throw new LogicException('File sudah divalidasi wajib ada.');
    }
}
