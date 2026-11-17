<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data anak dari formulir pendaftaran resmi sekolah. Semua kolom boleh kosong: murid lama tidak diubah dan
     * tertandai `data_lengkap = false`. NIK menjadi unik; NIK murid yang sudah dihapus dikosongkan seperti NISN.
     */
    public function up(): void
    {
        $dobel = DB::table('murid')->whereNull('deleted_at')->whereNotNull('nik')
            ->groupBy('nik')->havingRaw('COUNT(*) > 1')->pluck('nik');

        if ($dobel->isNotEmpty()) {
            $nama = DB::table('murid')->whereNull('deleted_at')->whereIn('nik', $dobel)->orderBy('nik')->pluck('nama_lengkap')->implode(', ');

            throw new RuntimeException("NIK dipakai lebih dari satu murid: {$nama}. Perbaiki NIK murid itu dulu, lalu jalankan migration lagi.");
        }

        DB::table('murid')->whereNotNull('deleted_at')->whereNotNull('nik')->update(['nik' => null]);

        Schema::table('murid', function (Blueprint $table) {
            $table->unique('nik');
            $table->after('alamat', function (Blueprint $table) {
                $table->string('desa_kelurahan', 100)->nullable();
                $table->string('kecamatan', 100)->nullable();
                $table->string('kabupaten_kota', 100)->nullable();
                $table->string('provinsi', 100)->nullable();
            });
            $table->after('anak_ke', function (Blueprint $table) {
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
                $table->boolean('data_lengkap')->default(false)->index();
                $table->timestamp('kk_diterima_at')->nullable();
                $table->foreignId('kk_diterima_oleh')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('akta_diterima_at')->nullable();
                $table->foreignId('akta_diterima_oleh')->nullable()->constrained('users')->nullOnDelete();
            });
        });
    }

    public function down(): void
    {
        Schema::table('murid', function (Blueprint $table) {
            $table->dropUnique(['nik']);
            $table->dropConstrainedForeignId('kk_diterima_oleh');
            $table->dropConstrainedForeignId('akta_diterima_oleh');
            $table->dropIndex(['data_lengkap']);
            $table->dropColumn([
                'desa_kelurahan', 'kecamatan', 'kabupaten_kota', 'provinsi', 'asal_sekolah', 'kewarganegaraan',
                'jumlah_saudara_kandung', 'jumlah_saudara_tiri', 'jumlah_saudara_angkat', 'status_yatim_piatu',
                'bahasa_sehari_hari', 'jarak_rumah_km', 'golongan_darah', 'riwayat_penyakit', 'tinggi_badan', 'berat_badan',
                'imunisasi', 'imunisasi_lainnya', 'ciri_khusus', 'kontak_utama', 'data_lengkap', 'kk_diterima_at', 'akta_diterima_at',
            ]);
        });
    }
};
