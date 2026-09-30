<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tidak ada lagi alur setujui/tolak pendaftaran guru. Riwayat persetujuan lama tetap ada di log aktivitas `guru`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guru', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disetujui_oleh');
            $table->dropColumn(['disetujui_at', 'alasan_penolakan']);
        });
    }

    public function down(): void
    {
        Schema::table('guru', function (Blueprint $table) {
            $table->foreignId('disetujui_oleh')->nullable()->after('tampil_di_landing')->constrained('users')->nullOnDelete();
            $table->timestamp('disetujui_at')->nullable()->after('disetujui_oleh');
            $table->text('alasan_penolakan')->nullable()->after('disetujui_at');
        });
    }
};
