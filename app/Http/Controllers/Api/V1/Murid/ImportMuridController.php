<?php

namespace App\Http\Controllers\Api\V1\Murid;

use App\Exports\TemplateImportMuridExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Murid\ImportMuridRequest;
use App\Models\User;
use App\Services\ImportMuridService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Import murid massal dari Excel/CSV (A2.14): unduh template, pratinjau per baris, lalu simpan baris yang valid.
 */
class ImportMuridController extends Controller
{
    public function __construct(private readonly ImportMuridService $importMurid) {}

    /**
     * Template .xlsx: sheet Murid berisi judul kolom, sheet Petunjuk berisi aturan setiap kolom. Kolom NISN, NIK, dan
     * tanggal berformat teks.
     */
    #[Response(200, 'Template import murid (.xlsx)', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function template(): BinaryFileResponse
    {
        return Excel::download(new TemplateImportMuridExport, 'template-import-murid.xlsx');
    }

    /**
     * Memeriksa setiap baris file tanpa menyimpan apa pun. `nomor` = nomor baris di spreadsheet (judul kolom di baris
     * 1). `data` berisi semua kolom template setelah dirapikan (tanggal menjadi YYYY-MM-DD, status kosong menjadi `aktif`,
     * kolom pilihan menjadi kode).
     * Kolom wajib yang tidak ada, file kosong, file tidak terbaca, atau lebih dari 500 baris ditolak 422
     * `VALIDATION_ERROR` di field `file`.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         ringkasan: array{total: int, valid: int, invalid: int},
     *         baris: list<array{
     *             nomor: int,
     *             data: array{nama_lengkap: string|null, nama_panggilan: string|null, nisn: string|null, nik: string|null, jenis_kelamin: string|null, tempat_lahir: string|null, tanggal_lahir: string|null, agama: string|null, alamat: string|null, desa_kelurahan: string|null, kecamatan: string|null, kabupaten_kota: string|null, provinsi: string|null, kewarganegaraan: string|null, anak_ke: string|null, jumlah_saudara_kandung: string|null, jumlah_saudara_tiri: string|null, jumlah_saudara_angkat: string|null, status_yatim_piatu: string|null, asal_sekolah: string|null, bahasa_sehari_hari: string|null, jarak_rumah_km: string|null, golongan_darah: string|null, riwayat_penyakit: string|null, tinggi_badan: string|null, berat_badan: string|null, imunisasi: string|null, imunisasi_lainnya: string|null, ciri_khusus: string|null, catatan_khusus: string|null, kontak_utama: string|null, tanggal_masuk: string|null, status: string, tanggal_status: string|null, tahun_lulus: string|null, sekolah_tujuan: string|null, keterangan: string|null, kelas: string|null, ayah_status: string|null, ayah_nama: string|null, ayah_tempat_lahir: string|null, ayah_tanggal_lahir: string|null, ayah_agama: string|null, ayah_kewarganegaraan: string|null, ayah_pendidikan_terakhir: string|null, ayah_pekerjaan: string|null, ayah_pekerjaan_lainnya: string|null, ayah_penghasilan: string|null, ayah_alamat_rumah: string|null, ayah_alamat_kantor: string|null, ayah_no_hp: string|null, ibu_status: string|null, ibu_nama: string|null, ibu_tempat_lahir: string|null, ibu_tanggal_lahir: string|null, ibu_agama: string|null, ibu_kewarganegaraan: string|null, ibu_pendidikan_terakhir: string|null, ibu_pekerjaan: string|null, ibu_pekerjaan_lainnya: string|null, ibu_penghasilan: string|null, ibu_alamat_rumah: string|null, ibu_alamat_kantor: string|null, ibu_no_hp: string|null, wali_hubungan: string|null, wali_hubungan_lainnya: string|null, wali_nama: string|null, wali_tempat_lahir: string|null, wali_tanggal_lahir: string|null, wali_agama: string|null, wali_kewarganegaraan: string|null, wali_pendidikan_terakhir: string|null, wali_pekerjaan: string|null, wali_pekerjaan_lainnya: string|null, wali_penghasilan: string|null, wali_alamat_rumah: string|null, wali_alamat_kantor: string|null, wali_no_hp: string|null},
     *             valid: bool,
     *             alasan: list<string>
     *         }>
     *     },
     *     meta: null
     * }
     */
    public function pratinjau(ImportMuridRequest $request): JsonResponse
    {
        $hasil = $this->importMurid->pratinjau($request->berkas());

        return ApiResponse::success($hasil, "{$hasil['ringkasan']['valid']} dari {$hasil['ringkasan']['total']} baris siap diimpor.");
    }

    /**
     * Memeriksa ulang file yang sama, lalu menyimpan semua baris valid dalam satu transaksi; baris invalid dilewati
     * dan dikembalikan di `gagal` dengan bentuk yang sama seperti pratinjau. Murid disimpan tanpa NIS; akun wali murid
     * aktif dibuat saat Kepala Sekolah mengisi NIS. Murid aktif ditempatkan di `kelas` kalau diisi. Murid lulus,
     * pindah, dan keluar tidak ikut generate tagihan. Dicatat di log aktivitas `murid` (event `diimpor`).
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         dibuat: int,
     *         gagal: list<array{
     *             nomor: int,
     *             data: array{nama_lengkap: string|null, nama_panggilan: string|null, nisn: string|null, nik: string|null, jenis_kelamin: string|null, tempat_lahir: string|null, tanggal_lahir: string|null, agama: string|null, alamat: string|null, desa_kelurahan: string|null, kecamatan: string|null, kabupaten_kota: string|null, provinsi: string|null, kewarganegaraan: string|null, anak_ke: string|null, jumlah_saudara_kandung: string|null, jumlah_saudara_tiri: string|null, jumlah_saudara_angkat: string|null, status_yatim_piatu: string|null, asal_sekolah: string|null, bahasa_sehari_hari: string|null, jarak_rumah_km: string|null, golongan_darah: string|null, riwayat_penyakit: string|null, tinggi_badan: string|null, berat_badan: string|null, imunisasi: string|null, imunisasi_lainnya: string|null, ciri_khusus: string|null, catatan_khusus: string|null, kontak_utama: string|null, tanggal_masuk: string|null, status: string, tanggal_status: string|null, tahun_lulus: string|null, sekolah_tujuan: string|null, keterangan: string|null, kelas: string|null, ayah_status: string|null, ayah_nama: string|null, ayah_tempat_lahir: string|null, ayah_tanggal_lahir: string|null, ayah_agama: string|null, ayah_kewarganegaraan: string|null, ayah_pendidikan_terakhir: string|null, ayah_pekerjaan: string|null, ayah_pekerjaan_lainnya: string|null, ayah_penghasilan: string|null, ayah_alamat_rumah: string|null, ayah_alamat_kantor: string|null, ayah_no_hp: string|null, ibu_status: string|null, ibu_nama: string|null, ibu_tempat_lahir: string|null, ibu_tanggal_lahir: string|null, ibu_agama: string|null, ibu_kewarganegaraan: string|null, ibu_pendidikan_terakhir: string|null, ibu_pekerjaan: string|null, ibu_pekerjaan_lainnya: string|null, ibu_penghasilan: string|null, ibu_alamat_rumah: string|null, ibu_alamat_kantor: string|null, ibu_no_hp: string|null, wali_hubungan: string|null, wali_hubungan_lainnya: string|null, wali_nama: string|null, wali_tempat_lahir: string|null, wali_tanggal_lahir: string|null, wali_agama: string|null, wali_kewarganegaraan: string|null, wali_pendidikan_terakhir: string|null, wali_pekerjaan: string|null, wali_pekerjaan_lainnya: string|null, wali_penghasilan: string|null, wali_alamat_rumah: string|null, wali_alamat_kantor: string|null, wali_no_hp: string|null},
     *             valid: bool,
     *             alasan: list<string>
     *         }>
     *     },
     *     meta: null
     * }
     */
    public function simpan(ImportMuridRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $hasil = $this->importMurid->simpan($request->berkas(), $user);
        $gagal = count($hasil['gagal']);

        return ApiResponse::success($hasil, "{$hasil['dibuat']} murid diimpor".($gagal === 0 ? '.' : ", {$gagal} baris gagal."));
    }
}
