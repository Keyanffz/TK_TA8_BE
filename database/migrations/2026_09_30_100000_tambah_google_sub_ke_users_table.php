<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guru dan Kepala Sekolah login dengan Google. `sub` akun Google disimpan saat login pertama; login berikutnya
 * harus dari akun Google yang sama walaupun emailnya cocok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_sub')->nullable()->unique()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_sub']);
            $table->dropColumn('google_sub');
        });
    }
};
