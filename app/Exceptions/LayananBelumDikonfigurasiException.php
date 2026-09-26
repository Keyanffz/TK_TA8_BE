<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fitur yang bergantung pada konfigurasi server (misalnya `GOOGLE_CLIENT_ID`) belum bisa dipakai.
 * Klien menerima 503 `SERVER_ERROR` dengan `pesanPengguna`; pesan exception berisi penyebab
 * teknisnya dan tercatat di log.
 */
final class LayananBelumDikonfigurasiException extends RuntimeException
{
    public const STATUS = 503;

    public function __construct(public readonly string $pesanPengguna, string $pesanLog)
    {
        parent::__construct($pesanLog);
    }
}
