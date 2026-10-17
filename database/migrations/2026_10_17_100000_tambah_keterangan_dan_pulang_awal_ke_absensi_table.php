<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->text('keterangan')->nullable()->after('foto_path');
            $table->boolean('pulang_awal')->default(false)->after('keterangan');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropColumn(['keterangan', 'pulang_awal']);
        });
    }
};
