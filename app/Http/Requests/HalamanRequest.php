<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Daftar berpaginasi tanpa filter, pencarian, atau urutan (daftar publik di landing page).
 */
class HalamanRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return collect($this->aturanDaftar())->only(['page', 'per_page'])->all();
    }
}
