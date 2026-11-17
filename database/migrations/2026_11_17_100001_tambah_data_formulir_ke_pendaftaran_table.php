<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom data anak yang sama dengan murid, disalin ke murid saat diterima. `hubungan` hanya diisi pendaftaran dari
     * wali yang login; pendaftaran tanpa login memakai `kontak_utama`. `status_sebelum_mundur` dipakai saat Kepala
     * Sekolah membatalkan status mengundurkan diri.
     */
    public function up(): void
    {
        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->string('hubungan', 10)->nullable()->change();
            $table->after('alamat', function (Blueprint $table) {
                $table->string('desa_kelurahan', 100)->nullable();
                $table->string('kecamatan', 100)->nullable();
                $table->string('kabupaten_kota', 100)->nullable();
                $table->string('provinsi', 100)->nullable();
                $table->unsignedTinyInteger('anak_ke')->nullable();
                $table->string('asal_sekolah', 150)->nullable();
                $table->string('kewarganegaraan', 3)->nullable();
                $table->unsignedTinyInteger('jumlah_saudara_kandung')->nullable();
                $table->unsignedTinyInteger('jumlah_saudara_tiri')->nullable();
                $table->unsignedTinyInteger('jumlah_saudara_angkat')->nullable();
                $table->string('status_yatim_piatu', 15)->nullable();
                $table->string('bahasa_sehari_hari', 50)->nullable();
                $table->decimal('jarak_rumah_km', 5, 2)->nullable();
                $table->string('golongan_darah', 10)->nullable();
                $table->text('riwayat_penyakit')->nullable();
                $table->decimal('tinggi_badan', 4, 1)->nullable();
                $table->decimal('berat_badan', 4, 1)->nullable();
                $table->json('imunisasi')->nullable();
                $table->string('imunisasi_lainnya')->nullable();
                $table->text('ciri_khusus')->nullable();
                $table->string('kontak_utama', 10)->nullable();
                $table->timestamp('kk_diterima_at')->nullable();
                $table->foreignId('kk_diterima_oleh')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('akta_diterima_at')->nullable();
                $table->foreignId('akta_diterima_oleh')->nullable()->constrained('users')->nullOnDelete();
            });
            $table->string('status_sebelum_mundur', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        $tanpaHubungan = DB::table('pendaftaran')->whereNull('hubungan')->count();

        if ($tanpaHubungan > 0) {
            throw new RuntimeException("Ada {$tanpaHubungan} pendaftaran tanpa hubungan. Kolom hubungan tidak bisa dikembalikan menjadi wajib.");
        }

        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kk_diterima_oleh');
            $table->dropConstrainedForeignId('akta_diterima_oleh');
            $table->dropColumn([
                'desa_kelurahan', 'kecamatan', 'kabupaten_kota', 'provinsi', 'anak_ke', 'asal_sekolah', 'kewarganegaraan',
                'jumlah_saudara_kandung', 'jumlah_saudara_tiri', 'jumlah_saudara_angkat', 'status_yatim_piatu',
                'bahasa_sehari_hari', 'jarak_rumah_km', 'golongan_darah', 'riwayat_penyakit', 'tinggi_badan', 'berat_badan',
                'imunisasi', 'imunisasi_lainnya', 'ciri_khusus', 'kontak_utama', 'kk_diterima_at', 'akta_diterima_at',
                'status_sebelum_mundur',
            ]);
            $table->string('hubungan', 10)->nullable(false)->change();
        });
    }
};
