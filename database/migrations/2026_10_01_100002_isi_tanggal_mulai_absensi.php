<?php

use App\Support\AturanAbsensi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `absensi.tanggal_mulai` diisi tanggal migration ini dijalankan, supaya scheduler tidak menandai tidak hadir
 * untuk hari-hari sebelum absensi dipakai. Nilai yang sudah ada tidak ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pengaturan')->insertOrIgnore([
            'kunci' => AturanAbsensi::KUNCI_TANGGAL_MULAI,
            'nilai' => json_encode(today()->toDateString()),
            'grup' => AturanAbsensi::GRUP,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('pengaturan.semua');
    }

    public function down(): void
    {
        DB::table('pengaturan')->where('kunci', AturanAbsensi::KUNCI_TANGGAL_MULAI)->delete();
        Cache::forget('pengaturan.semua');
    }
};
