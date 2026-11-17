<?php

namespace App\Http\Requests\Concerns;

use App\Support\FormulirMurid;
use Illuminate\Validation\Validator;

/**
 * Pemeriksaan formulir data murid yang butuh beberapa field sekaligus (`KelengkapanFormulir`), dijalankan setelah
 * aturan per field. Field yang sudah punya error format tidak ditambahi pesan lagi.
 */
trait MemeriksaFormulirMurid
{
    /**
     * @return array<string, array<string, mixed>|null>
     */
    protected function isianOrangTua(): array
    {
        $isian = [];

        foreach (FormulirMurid::PERAN as $peran) {
            $nilai = $this->input($peran);
            $isian[$peran] = is_array($nilai) ? $nilai : null;
        }

        return $isian;
    }

    /**
     * @param  array<string, string>  $pesan
     */
    protected function tambahkanGalat(Validator $validator, array $pesan): void
    {
        foreach ($pesan as $field => $isi) {
            if (! $validator->errors()->has($field)) {
                $validator->errors()->add($field, $isi);
            }
        }
    }
}
