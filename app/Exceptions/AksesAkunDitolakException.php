<?php

namespace App\Exceptions;

use App\Enums\KodeError;
use App\Enums\StatusAkun;
use RuntimeException;

/**
 * Login dengan kredensial benar, tetapi akun sudah dinonaktifkan (B6.7).
 * Dirender sebagai 403 dengan kode ACCOUNT_INACTIVE.
 */
class AksesAkunDitolakException extends RuntimeException
{
    public function __construct(public readonly KodeError $kode, string $pesan)
    {
        parent::__construct($pesan);
    }

    public static function untuk(StatusAkun $status): self
    {
        return new self($status->kodeErrorAkses(), $status->pesanAksesDitolak());
    }
}
