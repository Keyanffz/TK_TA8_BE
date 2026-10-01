<?php

use App\Support\AturanAbsensi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Nilai awal grup pengaturan `absensi` untuk database yang sudah berjalan, supaya halaman pengaturan absensi
 * langsung punya isi tanpa menjalankan ulang PengaturanSeeder. Kunci yang sudah ada tidak ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (AturanAbsensi::BAWAAN as $kunci => $nilai) {
            DB::table('pengaturan')->insertOrIgnore([
                'kunci' => $kunci,
                'nilai' => json_encode($nilai),
                'grup' => AturanAbsensi::GRUP,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('pengaturan.semua');
    }

    public function down(): void
    {
        DB::table('pengaturan')->where('grup', AturanAbsensi::GRUP)->delete();
        Cache::forget('pengaturan.semua');
    }
};
