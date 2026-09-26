<?php

namespace App\Exceptions;

use App\Enums\KodeError;
use App\Enums\StatusAkun;
use RuntimeException;

/**
 * Login dengan kredensial benar, tetapi akun belum atau tidak lagi aktif (B6.7).
 * Dirender sebagai 403 dengan kode ACCOUNT_PENDING / ACCOUNT_REJECTED / ACCOUNT_INACTIVE.
 */
class AksesAkunDitolakException extends RuntimeException
{
    public function __construct(public readonly KodeError $kode, string $pesan)
    {
        parent::__construct($pesan);
    }

    public static function untuk(StatusAkun $status, ?string $alasanPenolakan = null): self
    {
        $pesan = $status->pesanAksesDitolak();

        if ($status === StatusAkun::Ditolak && filled($alasanPenolakan)) {
            $pesan .= ' Alasan: '.$alasanPenolakan;
        }

        return new self($status->kodeErrorAkses(), $pesan);
    }
}
