<?php

use App\Support\AturanAbsensi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Kunci `absensi.jam_tanggal` (jam masuk dan pulang untuk rentang tanggal, misalnya Ramadan) untuk database yang sudah
 * berjalan, berisi daftar kosong. Database baru sudah mendapatkannya dari `isi_pengaturan_absensi`, jadi baris yang
 * sudah ada tidak ditimpa.
 */
return new class extends Migration
{
    private const KUNCI = 'absensi.jam_tanggal';

    public function up(): void
    {
        DB::table('pengaturan')->insertOrIgnore([
            'kunci' => self::KUNCI,
            'nilai' => json_encode(AturanAbsensi::BAWAAN[self::KUNCI]),
            'grup' => AturanAbsensi::GRUP,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('pengaturan.semua');
    }

    public function down(): void
    {
        DB::table('pengaturan')->where('kunci', self::KUNCI)->delete();
        Cache::forget('pengaturan.semua');
    }
};
