<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absensi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('tanggal')->index();
            $table->string('jenis', 10);
            $table->string('status', 20)->nullable()->index();
            $table->timestamp('waktu')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('akurasi_meter')->nullable();
            $table->unsignedInteger('jarak_meter')->nullable();
            $table->string('foto_path')->nullable();
            $table->text('catatan_koreksi')->nullable();
            $table->foreignId('dikoreksi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dikoreksi_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tanggal', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi');
    }
};
