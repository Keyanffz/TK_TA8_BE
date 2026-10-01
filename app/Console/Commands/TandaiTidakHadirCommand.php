<?php

namespace App\Console\Commands;

use App\Services\AbsensiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('absensi:tandai-tidak-hadir {--dry-run : Hanya menghitung tanpa menyimpan}')]
#[Description('Menandai tidak hadir peserta aktif yang belum absen masuk setelah jam masuk tutup, termasuk susulan hari kerja 7 hari ke belakang')]
class TandaiTidakHadirCommand extends Command
{
    public function handle(AbsensiService $absensi): int
    {
        $simulasi = (bool) $this->option('dry-run');
        $jumlah = $absensi->tandaiTidakHadir(now(), $simulasi);

        $this->info($simulasi
            ? "[dry run] {$jumlah} absensi tidak hadir akan dicatat."
            : "{$jumlah} absensi tidak hadir dicatat.");

        return self::SUCCESS;
    }
}
