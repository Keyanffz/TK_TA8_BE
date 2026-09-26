<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengumuman_murid', function (Blueprint $table) {
            $table->foreignId('pengumuman_id')->constrained('pengumuman')->cascadeOnDelete();
            $table->foreignId('murid_id')->constrained('murid')->cascadeOnDelete();

            $table->primary(['pengumuman_id', 'murid_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengumuman_murid');
    }
};
