<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pendaftaran_dokumen');
    }

    /**
     * Hanya struktur tabel yang kembali; baris yang terhapus di `up()` tidak bisa dipulihkan.
     */
    public function down(): void
    {
        Schema::create('pendaftaran_dokumen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pendaftaran_id')->constrained('pendaftaran')->cascadeOnDelete();
            $table->string('jenis', 20);
            $table->string('path');
            $table->timestamps();
        });
    }
};
