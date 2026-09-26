<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Slug dari judul untuk URL publik (pengumuman, album galeri). Kalau sudah dipakai, diberi akhiran `-2`, `-3`,
 * dan seterusnya. Query harus mencakup baris yang di-soft delete kalau tabelnya memakainya, karena kolom slug unik.
 */
final class SlugUnik
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function dari(Builder $query, string $judul, string $bawaan): string
    {
        $dasar = Str::slug($judul) ?: $bawaan;
        $slug = $dasar;

        for ($urut = 2; (clone $query)->where('slug', $slug)->exists(); $urut++) {
            $slug = "{$dasar}-{$urut}";
        }

        return $slug;
    }
}
