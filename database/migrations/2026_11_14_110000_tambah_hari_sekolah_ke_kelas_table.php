<?php

use App\Support\AturanAbsensi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Absensi murid memakai hari sekolah tiap kelas, bukan hari kerja absensi guru. Kelas yang sudah ada mulai dengan isi
 * `absensi.hari_kerja` saat migration dijalankan, supaya tanggal yang bisa dicatat tidak berubah sebelum diatur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kelas', function (Blueprint $table) {
            $table->json('hari_sekolah')->default(json_encode(AturanAbsensi::BAWAAN['absensi.hari_kerja']))->after('kapasitas');
        });

        $hariKerja = json_decode((string) DB::table('pengaturan')->where('kunci', 'absensi.hari_kerja')->value('nilai'), true);
        if (is_array($hariKerja) && $hariKerja !== []) {
            DB::table('kelas')->update(['hari_sekolah' => json_encode(array_map(intval(...), $hariKerja))]);
        }
    }

    public function down(): void
    {
        Schema::table('kelas', function (Blueprint $table) {
            $table->dropColumn('hari_sekolah');
        });
    }
};
