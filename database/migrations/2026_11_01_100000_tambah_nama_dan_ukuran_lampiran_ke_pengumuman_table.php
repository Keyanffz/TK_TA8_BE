<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengumuman', function (Blueprint $table) {
            $table->string('lampiran_nama')->nullable()->after('lampiran_path');
            $table->unsignedInteger('lampiran_ukuran')->nullable()->after('lampiran_nama');
        });
    }

    public function down(): void
    {
        Schema::table('pengumuman', function (Blueprint $table) {
            $table->dropColumn(['lampiran_nama', 'lampiran_ukuran']);
        });
    }
};
