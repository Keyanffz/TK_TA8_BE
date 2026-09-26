<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelas_murid', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('murid_id')->constrained('murid')->cascadeOnDelete();
            $table->string('status', 20)->default('aktif')->index();
            $table->timestamps();

            $table->unique(['kelas_id', 'murid_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelas_murid');
    }
};
