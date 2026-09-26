<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keringanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('murid_id')->constrained('murid')->restrictOnDelete();
            $table->foreignId('jenis_tagihan_id')->constrained('jenis_tagihan')->restrictOnDelete();
            $table->string('tipe', 20);
            $table->unsignedInteger('nilai');
            $table->string('alasan');
            $table->date('berlaku_mulai');
            $table->date('berlaku_sampai')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keringanan');
    }
};
