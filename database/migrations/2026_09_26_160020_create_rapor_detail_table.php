<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapor_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapor_id')->constrained('rapor')->cascadeOnDelete();
            $table->foreignId('elemen_penilaian_id')->constrained('elemen_penilaian')->restrictOnDelete();
            $table->text('deskripsi')->nullable();
            $table->string('foto_path')->nullable();
            $table->timestamps();

            $table->unique(['rapor_id', 'elemen_penilaian_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapor_detail');
    }
};
