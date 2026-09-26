<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('murid', function (Blueprint $table) {
            $table->id();
            $table->string('nis', 10)->unique();
            $table->string('nisn', 10)->nullable()->unique();
            $table->string('nik', 16)->nullable();
            $table->string('nama_lengkap');
            $table->string('nama_panggilan');
            $table->string('jenis_kelamin', 1);
            $table->string('tempat_lahir');
            $table->date('tanggal_lahir');
            $table->string('agama', 20);
            $table->text('alamat');
            $table->unsignedTinyInteger('anak_ke')->nullable();
            $table->string('foto_path')->nullable();
            $table->text('catatan_khusus')->nullable();
            $table->string('status', 20)->index();
            $table->date('tanggal_masuk');
            $table->date('tanggal_keluar')->nullable();
            $table->string('kode_tautan', 8)->nullable()->unique();
            $table->timestamp('kode_tautan_expired_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('murid');
    }
};
