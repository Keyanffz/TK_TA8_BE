<?php

namespace App\Exceptions;

use Illuminate\Support\Carbon;

/**
 * Generate tagihan bulanan untuk bulan yang tidak masuk rentang tahun ajaran aktif, atau saat belum ada
 * tahun ajaran aktif (`$tahunAjaran` null). Scheduler memakai data ini untuk memberi tahu Kepala Sekolah.
 */
class PeriodeDiLuarTahunAjaranException extends BusinessRuleException
{
    public function __construct(public readonly Carbon $periode, public readonly ?string $tahunAjaran, string $pesan)
    {
        parent::__construct($pesan);
    }
}
