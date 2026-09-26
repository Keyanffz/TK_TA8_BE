<?php

namespace App\Console\Commands;

use App\Services\JatuhTempoTagihanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tagihan:tandai-terlambat {--dry-run : Hanya menghitung tanpa mengubah status}')]
#[Description('Menandai tagihan belum bayar yang lewat jatuh tempo sebagai terlambat dan memberi tahu wali')]
class TandaiTagihanTerlambatCommand extends Command
{
    public function handle(JatuhTempoTagihanService $jatuhTempo): int
    {
        $simulasi = (bool) $this->option('dry-run');
        $jumlah = $jatuhTempo->tandaiTerlambat(today(), $simulasi);

        $this->info($simulasi
            ? "[dry run] {$jumlah} tagihan akan ditandai terlambat."
            : "{$jumlah} tagihan ditandai terlambat.");

        return self::SUCCESS;
    }
}
