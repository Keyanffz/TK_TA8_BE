<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Nomor berurutan dengan awalan tetap, misalnya NIS `TA20260001`, tagihan `INV-202609-00001`, dan
 * pembayaran `PAY-20260905-00001`. Nomor diambil dari nilai terbesar berawalan sama dengan kunci baris
 * (`lockForUpdate`), jadi harus dipanggil di dalam transaksi. Unique index di kolomnya tetap menjadi
 * penjaga terakhir.
 */
final class NomorUrut
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return int nomor urut pertama yang belum dipakai
     *
     * @throws BusinessRuleException
     */
    public static function berikutnya(Builder $query, string $kolom, string $awalan, int $digit): int
    {
        $terakhir = $query->where($kolom, 'like', $awalan.'%')->lockForUpdate()->max($kolom);
        $nomor = $terakhir === null ? 1 : (int) substr((string) $terakhir, strlen($awalan)) + 1;

        if ($nomor >= 10 ** $digit) {
            throw new BusinessRuleException("Nomor urut untuk awalan {$awalan} sudah habis.");
        }

        return $nomor;
    }

    public static function format(string $awalan, int $nomor, int $digit): string
    {
        return $awalan.str_pad((string) $nomor, $digit, '0', STR_PAD_LEFT);
    }
}
