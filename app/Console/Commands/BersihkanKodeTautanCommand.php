<?php

namespace App\Console\Commands;

use App\Services\KodeTautanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kode-tautan:bersihkan {--dry-run : Hanya menghitung kode yang kedaluwarsa tanpa mengosongkannya}')]
#[Description('Mengosongkan kode tautan murid yang sudah kedaluwarsa')]
class BersihkanKodeTautanCommand extends Command
{
    public function handle(KodeTautanService $kodeTautan): int
    {
        $simulasi = (bool) $this->option('dry-run');
        $jumlah = $kodeTautan->bersihkanKedaluwarsa($simulasi);

        $this->info($simulasi
            ? "{$jumlah} kode tautan kedaluwarsa akan dikosongkan (dry run, tidak ada yang diubah)."
            : "{$jumlah} kode tautan kedaluwarsa dikosongkan.");

        return self::SUCCESS;
    }
}
