<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode tautan diganti akun wali otomatis per murid dan fitur tambah anak dengan NIS dan tanggal lahir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('murid', function (Blueprint $table) {
            $table->dropUnique(['kode_tautan']);
            $table->dropColumn(['kode_tautan', 'kode_tautan_expired_at']);
        });
    }

    public function down(): void
    {
        Schema::table('murid', function (Blueprint $table) {
            $table->string('kode_tautan', 8)->nullable()->unique()->after('tanggal_keluar');
            $table->timestamp('kode_tautan_expired_at')->nullable()->after('kode_tautan');
        });
    }
};
