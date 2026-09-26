<?php

namespace App\Enums;

/**
 * Nama token Sanctum sesuai perangkat yang login (field `perangkat` di A7).
 */
enum Perangkat: string
{
    case Web = 'web';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Mobile => 'Aplikasi mobile',
        };
    }
}
