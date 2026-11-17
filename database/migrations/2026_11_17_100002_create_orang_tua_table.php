<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ayah, ibu, dan wali dari formulir, milik satu murid atau satu pendaftaran (tepat satu dari dua FK, dijaga
     * service). Satu baris per peran. Ini data formulir, terpisah dari akun Wali Murid.
     */
    public function up(): void
    {
        Schema::create('orang_tua', function (Blueprint $table) {
            $table->id();
            $table->foreignId('murid_id')->nullable()->constrained('murid')->cascadeOnDelete();
            $table->foreignId('pendaftaran_id')->nullable()->constrained('pendaftaran')->cascadeOnDelete();
            $table->string('peran', 10);
            $table->string('status', 10)->nullable();
            $table->string('hubungan', 10)->nullable();
            $table->string('hubungan_lainnya', 50)->nullable();
            $table->string('nama')->nullable();
            $table->string('tempat_lahir', 100)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('agama', 20)->nullable();
            $table->string('kewarganegaraan', 3)->nullable();
            $table->string('pendidikan_terakhir', 20)->nullable();
            $table->string('pekerjaan', 30)->nullable();
            $table->string('pekerjaan_lainnya', 100)->nullable();
            $table->string('penghasilan', 30)->nullable();
            $table->boolean('alamat_sama_dengan_anak')->default(false);
            $table->text('alamat_rumah')->nullable();
            $table->text('alamat_kantor')->nullable();
            $table->string('no_hp', 20)->nullable();
            $table->timestamps();

            $table->unique(['murid_id', 'peran']);
            $table->unique(['pendaftaran_id', 'peran']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orang_tua');
    }
};
