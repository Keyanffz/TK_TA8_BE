<?php

namespace App\Console\Commands;

use App\Services\AbsensiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('absensi:hapus-foto-lama {--dry-run : Hanya menghitung tanpa menghapus}')]
#[Description('Menghapus file foto dan lampiran absensi yang melewati masa simpan; data absensinya tetap ada')]
class HapusFotoAbsensiLamaCommand extends Command
{
    public function handle(AbsensiService $absensi): int
    {
        $simulasi = (bool) $this->option('dry-run');
        $jumlah = $absensi->hapusFotoLama(today(), $simulasi);

        $this->info($simulasi
            ? "[dry run] {$jumlah} foto dan lampiran absensi akan dihapus."
            : "{$jumlah} foto dan lampiran absensi dihapus.");

        return self::SUCCESS;
    }
}
