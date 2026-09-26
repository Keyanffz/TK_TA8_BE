<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('murid_id')->constrained('murid')->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->unsignedTinyInteger('semester');
            $table->decimal('tinggi_badan', 5, 1)->nullable();
            $table->decimal('berat_badan', 5, 1)->nullable();
            $table->text('catatan_guru')->nullable();
            $table->string('status', 20)->index();
            $table->text('catatan_revisi')->nullable();
            $table->foreignId('dibuat_oleh')->constrained('guru')->restrictOnDelete();
            $table->timestamp('diajukan_at')->nullable();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('terbit_at')->nullable();
            $table->timestamps();

            $table->unique(['murid_id', 'tahun_ajaran_id', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapor');
    }
};
