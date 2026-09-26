<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('murid_wali', function (Blueprint $table) {
            $table->foreignId('murid_id')->constrained('murid')->cascadeOnDelete();
            $table->foreignId('wali_murid_id')->constrained('wali_murid')->cascadeOnDelete();
            $table->string('hubungan', 10);
            $table->boolean('is_kontak_utama')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['murid_id', 'wali_murid_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('murid_wali');
    }
};
