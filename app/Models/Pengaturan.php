<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris per kunci di A4 "Kunci pengaturan". `nilai` disimpan sebagai JSON sehingga bisa berisi
 * string, angka, boolean, array, atau null sesuai kuncinya.
 */
#[Fillable(['kunci', 'nilai', 'grup'])]
class Pengaturan extends Model
{
    protected $table = 'pengaturan';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nilai' => 'json',
        ];
    }
}
