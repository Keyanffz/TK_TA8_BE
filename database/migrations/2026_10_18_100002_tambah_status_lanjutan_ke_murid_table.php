<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tanggal_keluar` yang sudah ada tetap menjadi tanggal status. Murid yang sudah lulus (lewat kenaikan kelas atau
 * ubah data) diberi tahun lulus dari tahun tanggal keluarnya, supaya langsung muncul di daftar alumni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('murid', function (Blueprint $table) {
            $table->unsignedSmallInteger('tahun_lulus')->nullable()->index()->after('tanggal_keluar');
            $table->string('sekolah_tujuan', 150)->nullable()->after('tahun_lulus');
            $table->text('keterangan_status')->nullable()->after('sekolah_tujuan');
        });

        // Dihitung di PHP karena fungsi tahun berbeda di MySQL/MariaDB dan SQLite.
        DB::table('murid')->where('status', 'lulus')->whereNotNull('tanggal_keluar')->select(['id', 'tanggal_keluar'])
            ->chunkById(200, function ($murid): void {
                foreach ($murid as $satu) {
                    DB::table('murid')->where('id', $satu->id)->update(['tahun_lulus' => Carbon::parse($satu->tanggal_keluar)->year]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('murid', function (Blueprint $table) {
            $table->dropIndex(['tahun_lulus']);
            $table->dropColumn(['tahun_lulus', 'sekolah_tujuan', 'keterangan_status']);
        });
    }
};
