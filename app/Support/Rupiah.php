<?php

namespace App\Support;

/**
 * Uang disimpan sebagai integer rupiah (A2.10) dan ditulis "Rp 150.000" di notifikasi, kwitansi, dan ekspor.
 */
final class Rupiah
{
    public static function format(int $jumlah): string
    {
        return 'Rp '.number_format($jumlah, 0, ',', '.');
    }
}
