<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wali murid login dengan NIS anak (username) dan password, bukan Google. Akun wali tidak punya email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 20)->nullable()->unique()->after('email');
            $table->boolean('wajib_ganti_password')->default(false)->after('password');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'wajib_ganti_password']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
