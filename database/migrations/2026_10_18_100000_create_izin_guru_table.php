<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('izin_guru', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id')->constrained('guru')->cascadeOnDelete();
            $table->string('modul', 30);
            $table->string('level', 10);
            $table->timestamps();

            $table->unique(['guru_id', 'modul']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('izin_guru');
    }
};
