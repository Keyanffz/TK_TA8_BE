<?php

namespace App\Http\Requests\Absensi;

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * `POST /absensi/koreksi`: absen masuk atau pulang untuk tanggal yang belum punya baris jenis itu. Tidak hadir
 * tidak bisa dibuat di sini; baris itu dibuat scheduler.
 */
class KoreksiAbsensiBaruRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Peserta absensi: guru aktif atau Kepala Sekolah. */
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            /** Tanggal `YYYY-MM-DD`. */
            'tanggal' => ['required', 'date_format:Y-m-d'],
            /** Bawaan `masuk`. */
            'jenis' => ['sometimes', Rule::enum(JenisAbsensi::class)],
            /** Wajib untuk absen masuk; tidak boleh dikirim untuk absen pulang. */
            'status' => ['required_unless:jenis,pulang', 'prohibited_if:jenis,pulang', Rule::enum(StatusAbsensi::class)->except([StatusAbsensi::TidakHadir])],
            'catatan' => ['required', 'string', 'max:500'],
            /** Opsional: foto atau dokumen pendukung (JPG, PNG, WebP, atau PDF, maks 5 MB). Body multipart kalau dikirim. */
            'lampiran' => ['nullable', ...MediaService::aturanDokumen()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required_unless' => 'Status wajib diisi untuk absen masuk.',
            'status.prohibited_if' => 'Absen pulang tidak punya status. Kosongkan status.',
            'lampiran.mimes' => 'Lampiran harus berupa PDF atau gambar (JPG, PNG, WebP).',
            'lampiran.max' => 'Ukuran lampiran maksimal 5 MB.',
        ];
    }

    public function tanggal(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('tanggal')->toString())->startOfDay();
    }

    public function jenis(): JenisAbsensi
    {
        return $this->enum('jenis', JenisAbsensi::class) ?? JenisAbsensi::Masuk;
    }

    /**
     * Null untuk absen pulang.
     */
    public function status(): ?StatusAbsensi
    {
        return $this->enum('status', StatusAbsensi::class);
    }

    public function catatan(): string
    {
        return $this->string('catatan')->trim()->toString();
    }

    public function lampiran(): ?UploadedFile
    {
        $file = $this->file('lampiran');

        return $file instanceof UploadedFile ? $file : null;
    }
}
