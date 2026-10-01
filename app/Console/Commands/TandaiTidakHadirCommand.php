<?php

namespace App\Console\Commands;

use App\Services\AbsensiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('absensi:tandai-tidak-hadir {--dry-run : Hanya menghitung tanpa menyimpan}')]
#[Description('Menandai tidak hadir peserta aktif yang belum absen masuk setelah jam masuk tutup di hari kerja')]
class TandaiTidakHadirCommand extends Command
{
    public function handle(AbsensiService $absensi): int
    {
        $simulasi = (bool) $this->option('dry-run');
        $jumlah = $absensi->tandaiTidakHadir(now(), $simulasi);

        $this->info($simulasi
            ? "[dry run] {$jumlah} peserta akan ditandai tidak hadir."
            : "{$jumlah} peserta ditandai tidak hadir.");

        return self::SUCCESS;
    }
}
