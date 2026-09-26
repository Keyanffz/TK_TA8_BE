<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Services\TagihanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('tagihan:generate {--periode= : Bulan tagihan YYYY-MM, bawaan bulan ini} {--dry-run : Hanya menghitung tanpa menyimpan}')]
#[Description('Membuat tagihan bulanan untuk semua murid aktif (idempoten)')]
class GenerateTagihanCommand extends Command
{
    public function handle(TagihanService $tagihanService): int
    {
        $opsiPeriode = $this->option('periode');

        if (is_string($opsiPeriode) && ! Carbon::canBeCreatedFromFormat($opsiPeriode, 'Y-m')) {
            $this->error('Format --periode harus YYYY-MM, misalnya 2026-10.');

            return self::INVALID;
        }

        $periode = is_string($opsiPeriode) ? Carbon::createFromFormat('Y-m-d', $opsiPeriode.'-01')->startOfDay() : now()->startOfMonth();
        $simulasi = (bool) $this->option('dry-run');

        try {
            $hasil = $tagihanService->generateBulanan($periode, simulasi: $simulasi);
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%sTagihan %s: %d %s, %d sudah ada.',
            $simulasi ? '[dry run] ' : '',
            $periode->translatedFormat('F Y'),
            $hasil['dibuat'],
            $simulasi ? 'akan dibuat' : 'dibuat',
            $hasil['dilewati'],
        ));

        return self::SUCCESS;
    }
}
