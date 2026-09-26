<?php

namespace App\Console\Commands;

use App\Services\JatuhTempoTagihanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tagihan:pengingat {--dry-run : Hanya menghitung tanpa mengirim notifikasi}')]
#[Description('Mengirim pengingat ke wali untuk tagihan yang jatuh tempo H-keuangan.hari_pengingat')]
class PengingatTagihanCommand extends Command
{
    public function handle(JatuhTempoTagihanService $jatuhTempo): int
    {
        $simulasi = (bool) $this->option('dry-run');
        $jumlah = $jatuhTempo->kirimPengingat(today(), $simulasi);

        $this->info($simulasi
            ? "[dry run] {$jumlah} tagihan akan diingatkan."
            : "Pengingat dikirim untuk {$jumlah} tagihan.");

        return self::SUCCESS;
    }
}
