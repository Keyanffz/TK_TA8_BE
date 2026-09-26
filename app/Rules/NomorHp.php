<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nomor HP Indonesia dalam format lokal `08…` (10–15 digit).
 */
class NomorHp implements ValidationRule
{
    private const POLA = '/^08\d{8,13}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::POLA, $value) !== 1) {
            $fail('Nomor HP harus diawali 08 dan hanya berisi angka, misalnya 081234567890.');
        }
    }
}
