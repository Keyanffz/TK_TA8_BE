<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pendaftaran PPDB bisa dikirim tanpa login (POST /public/pendaftaran), jadi belum punya wali. Kolom ini terisi
 * saat pendaftaran diterima dan akun wali dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->foreignId('wali_murid_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->foreignId('wali_murid_id')->nullable(false)->change();
        });
    }
};
