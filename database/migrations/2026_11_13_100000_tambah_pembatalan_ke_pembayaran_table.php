<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sejak pembayaran hanya tunai dan dicatat petugas, tidak ada lagi yang memverifikasi bukti transfer.
     * Pembayaran `menunggu` yang tersisa harus diterima atau ditolak di versi sebelum ini, supaya tidak
     * tertinggal di status yang tidak bisa diproses.
     */
    public function up(): void
    {
        $menunggu = DB::table('pembayaran')->where('status', 'menunggu')->count();

        if ($menunggu > 0) {
            throw new RuntimeException("Masih ada {$menunggu} pembayaran berstatus menunggu. Terima atau tolak dulu di versi aplikasi sebelum ini, lalu jalankan migrate lagi.");
        }

        Schema::table('pembayaran', function (Blueprint $table) {
            $table->text('alasan_pembatalan')->nullable();
            $table->foreignId('dibatalkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dibatalkan_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dibatalkan_oleh');
            $table->dropColumn(['alasan_pembatalan', 'dibatalkan_at']);
        });
    }
};
