<?php

namespace App\Console\Commands;

use App\Services\PengaturanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pengaturan:bersihkan-gambar {--dry-run : Hanya menghitung tanpa menghapus}')]
#[Description('Menghapus gambar pengaturan yang diunggah tetapi tidak dipakai dan sudah lebih dari sehari')]
class BersihkanGambarPengaturanCommand extends Command
{
    public function handle(PengaturanService $pengaturan): int
    {
        $simulasi = (bool) $this->option('dry-run');
        $jumlah = $pengaturan->bersihkanGambar(now(), $simulasi);

        $this->info($simulasi
            ? "[dry run] {$jumlah} gambar pengaturan akan dihapus."
            : "{$jumlah} gambar pengaturan dihapus.");

        return self::SUCCESS;
    }
}
