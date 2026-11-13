<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akses_absen_susulan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('tanggal');
            $table->string('jenis', 10);
            $table->text('catatan');
            $table->foreignId('diberikan_oleh')->constrained('users')->restrictOnDelete();
            $table->timestamp('berlaku_sampai');
            $table->timestamp('dipakai_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('akses_absen_susulan');
    }
};
