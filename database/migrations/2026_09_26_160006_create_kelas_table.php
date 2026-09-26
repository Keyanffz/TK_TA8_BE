<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->string('nama', 50);
            $table->string('tingkat', 1);
            $table->foreignId('wali_kelas_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->foreignId('guru_pendamping_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->unsignedSmallInteger('kapasitas')->default(20);
            $table->timestamps();

            $table->unique(['tahun_ajaran_id', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelas');
    }
};
