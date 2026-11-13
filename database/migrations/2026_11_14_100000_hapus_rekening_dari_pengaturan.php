<?php

use App\Services\PengaturanService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rekening sekolah tidak dipakai lagi sejak pembayaran hanya tunai. Cache dibuang supaya barisnya tidak ikut
 * terkirim dari cache lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pengaturan')->where('kunci', 'keuangan.rekening')->delete();
        Cache::forget(PengaturanService::KUNCI_CACHE);
    }
};
