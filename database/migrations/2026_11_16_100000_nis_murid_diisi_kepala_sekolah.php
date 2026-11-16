<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NIS tidak lagi dibuat otomatis: murid baru tanpa NIS sampai Kepala Sekolah mengisinya, dengan format bebas
     * sepanjang username akun wali (20). NIS murid lama tidak diubah.
     */
    public function up(): void
    {
        Schema::table('murid', function (Blueprint $table) {
            $table->string('nis', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        $tidakMuat = DB::table('murid')->whereNull('nis')->orWhereRaw('LENGTH(nis) > 10')->count();

        if ($tidakMuat > 0) {
            throw new RuntimeException("Ada {$tidakMuat} murid tanpa NIS atau dengan NIS lebih dari 10 karakter. Kolom nis tidak bisa dikembalikan ke wajib 10 karakter.");
        }

        Schema::table('murid', function (Blueprint $table) {
            $table->string('nis', 10)->nullable(false)->change();
        });
    }
};
