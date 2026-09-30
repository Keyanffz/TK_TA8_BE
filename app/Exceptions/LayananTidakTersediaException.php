<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fitur yang bergantung pada konfigurasi server atau layanan luar (misalnya `GOOGLE_CLIENT_ID` dan kunci publik
 * Google) belum bisa dipakai. Klien menerima 503 `SERVER_ERROR` dengan `pesanPengguna`; pesan exception berisi
 * penyebab teknisnya dan tercatat di log.
 */
final class LayananTidakTersediaException extends RuntimeException
{
    public const STATUS = 503;

    public function __construct(public readonly string $pesanPengguna, string $pesanLog, ?\Throwable $sebelumnya = null)
    {
        parent::__construct($pesanLog, previous: $sebelumnya);
    }
}
