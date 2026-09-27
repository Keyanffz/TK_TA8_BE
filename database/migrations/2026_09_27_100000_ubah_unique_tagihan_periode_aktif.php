<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan bulanan yang dibatalkan boleh dibuat ulang untuk periode yang sama. `periode_aktif` berisi periode
 * selama tagihan belum dibatalkan dan NULL setelah dibatalkan; NULL tidak dianggap sama oleh unique index
 * di MySQL, MariaDB, dan SQLite, jadi unique (murid, jenis, periode_aktif) hanya menjaga tagihan yang aktif.
 * Tagihan sekali bayar (`periode` NULL) tetap tidak dijaga index, sama seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->date('periode_aktif')->nullable()->virtualAs("case when status = 'dibatalkan' then null else periode end");
        });

        // Unique baru dibuat sebelum yang lama dihapus: MySQL/MariaDB memakai index berawalan murid_id
        // untuk foreign key murid_id dan menolak menghapusnya kalau tidak ada penggantinya.
        Schema::table('tagihan', function (Blueprint $table) {
            $table->unique(['murid_id', 'jenis_tagihan_id', 'periode_aktif']);
        });

        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropUnique(['murid_id', 'jenis_tagihan_id', 'periode']);
        });
    }

    /**
     * Gagal kalau sudah ada tagihan dibatalkan yang dibuat ulang untuk periode yang sama.
     */
    public function down(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->unique(['murid_id', 'jenis_tagihan_id', 'periode']);
        });

        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropUnique(['murid_id', 'jenis_tagihan_id', 'periode_aktif']);
            $table->dropColumn('periode_aktif');
        });
    }
};
