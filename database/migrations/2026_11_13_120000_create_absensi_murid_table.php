<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absensi_murid', function (Blueprint $table) {
            $table->id();
            $table->foreignId('murid_id')->constrained('murid')->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->date('tanggal');
            $table->string('status', 10);
            $table->string('keterangan')->nullable();
            $table->foreignId('dicatat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['murid_id', 'tanggal']);
            $table->index(['kelas_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi_murid');
    }
};
