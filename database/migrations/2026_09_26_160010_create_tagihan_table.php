<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tagihan', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 16)->unique();
            $table->foreignId('murid_id')->constrained('murid')->restrictOnDelete();
            $table->foreignId('jenis_tagihan_id')->constrained('jenis_tagihan')->restrictOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->date('periode')->nullable()->index();
            $table->unsignedBigInteger('nominal');
            $table->unsignedBigInteger('potongan')->default(0);
            $table->unsignedBigInteger('total');
            $table->date('jatuh_tempo')->index();
            $table->string('status', 20)->index();
            $table->timestamp('lunas_at')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['murid_id', 'jenis_tagihan_id', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagihan');
    }
};
