<?php

namespace App\Http\Requests\Absensi;

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * `POST /absensi` (multipart). Di sini hanya bentuk isian; hari kerja, jam, jarak, dan akurasi diperiksa
 * `AbsensiService` dengan jam server. Izin dan sakit tidak butuh lokasi, tetapi butuh keterangan dan foto.
 */
class AbsenRequest extends FormRequest
{
    /** Foto swafoto sudah dikompres di FE, jadi batasnya lebih kecil dari unggahan lain (B5). */
    public const UKURAN_MAKSIMAL_FOTO_KB = 2 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jenis' => ['required', Rule::enum(JenisAbsensi::class)],
            /** Hanya untuk absen masuk: `izin` atau `sakit`. Kosong untuk absen masuk biasa (hadir atau terlambat dari jam server). */
            'status' => ['nullable', 'prohibited_if:jenis,pulang', Rule::enum(StatusAbsensi::class)->only([StatusAbsensi::Izin, StatusAbsensi::Sakit])],
            /** Wajib kecuali izin atau sakit. */
            'latitude' => ['required_unless:status,izin,sakit', 'numeric', 'between:-90,90'],
            /** Wajib kecuali izin atau sakit. */
            'longitude' => ['required_unless:status,izin,sakit', 'numeric', 'between:-180,180'],
            /** Akurasi lokasi dari perangkat, dalam meter. Wajib kecuali izin atau sakit. */
            'akurasi' => ['required_unless:status,izin,sakit', 'numeric', 'gt:0', 'max:100000'],
            /** Alasan dari peserta. Wajib untuk izin atau sakit. */
            'keterangan' => ['required_if:status,izin,sakit', 'nullable', 'string', 'max:500'],
            /** Swafoto, atau foto surat izin/keterangan sakit. */
            'foto' => ['required', 'image', 'mimes:'.implode(',', MediaService::EKSTENSI_GAMBAR), 'max:'.self::UKURAN_MAKSIMAL_FOTO_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $lokasiBelumTerbaca = 'Lokasi belum terbaca. Izinkan akses lokasi lalu coba lagi.';

        return [
            'foto.max' => 'Ukuran foto paling besar 2 MB.',
            'foto.required' => 'Foto wajib diambil sebelum absen.',
            'latitude.required_unless' => $lokasiBelumTerbaca,
            'longitude.required_unless' => $lokasiBelumTerbaca,
            'akurasi.required_unless' => $lokasiBelumTerbaca,
            'status.prohibited_if' => 'Absen pulang tidak punya status.',
            'keterangan.required_if' => 'Tulis keterangan izin atau sakit.',
        ];
    }

    public function jenis(): JenisAbsensi
    {
        return $this->enum('jenis', JenisAbsensi::class) ?? throw new LogicException('Jenis absensi sudah divalidasi wajib ada.');
    }

    /**
     * Izin atau sakit, null untuk absen biasa.
     */
    public function berhalangan(): ?StatusAbsensi
    {
        return $this->enum('status', StatusAbsensi::class);
    }

    /**
     * Lokasi perangkat, atau null untuk izin dan sakit (lokasi tidak dipakai walau dikirim).
     *
     * @return array{latitude: float, longitude: float, akurasi: float}|null
     */
    public function posisi(): ?array
    {
        return $this->berhalangan() !== null ? null : [
            'latitude' => $this->float('latitude'),
            'longitude' => $this->float('longitude'),
            'akurasi' => $this->float('akurasi'),
        ];
    }

    public function keterangan(): ?string
    {
        return $this->filled('keterangan') ? $this->string('keterangan')->trim()->toString() : null;
    }

    public function foto(): UploadedFile
    {
        $file = $this->file('foto');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Foto sudah divalidasi wajib ada.');
    }
}
