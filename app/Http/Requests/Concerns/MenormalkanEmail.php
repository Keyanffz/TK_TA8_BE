<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

/**
 * Email akun disimpan huruf kecil, jadi input juga dinormalkan sebelum validasi `unique` dan pencarian akun.
 * Tanpa ini, `Guru@Gmail.com` lolos cek unik di SQLite dan tidak ditemukan saat login.
 */
trait MenormalkanEmail
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }
}
