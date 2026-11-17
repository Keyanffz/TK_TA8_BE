<?php

namespace App\Http\Requests\Murid;

use App\Enums\JenisKelamin;
use App\Http\Requests\Concerns\MemeriksaFormulirMurid;
use App\Services\MediaService;
use App\Support\FormulirMurid;
use App\Support\KelengkapanFormulir;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /murid` dan `PUT /murid/{id}` (multipart jika menyertakan foto). NIS diisi Kepala Sekolah lewat
 * `PATCH /murid/{id}/nis`. Status dan data kelulusan/kepindahan hanya diubah lewat `PATCH /murid/{id}/status`, supaya
 * setiap perubahan status tercatat di riwayat. Staf boleh menyimpan formulir yang belum lengkap (wajib hanya data
 * diri lama); murid itu tertandai `data_lengkap = false`. Kontak utama tetap harus orang yang masih hidup dan
 * punya nomor HP.
 */
class SimpanMuridRequest extends FormRequest
{
    use MemeriksaFormulirMurid;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nisn' => ['nullable', 'digits:10', Rule::unique('murid', 'nisn')->ignore($this->route('id'))],
            ...self::aturanDataDiri(),
            'nik' => ['nullable', 'digits:16', Rule::unique('murid', 'nik')->ignore($this->route('id'))],
            ...FormulirMurid::aturanAnak(),
            ...FormulirMurid::aturanOrangTua(),
            'foto' => ['nullable', ...MediaService::aturanGambar()],
            /** @ignoreParam */
            'status' => ['prohibited'],
            /** @ignoreParam */
            'tanggal_keluar' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return FormulirMurid::atribut();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->tambahkanGalat($validator, KelengkapanFormulir::masalahKontakUtama($this->all(), $this->isianOrangTua()))];
    }

    /**
     * Data diri murid selain NISN dan foto. Dipakai juga untuk setiap baris import murid, yang memeriksa NISN dan NIK
     * ganda sendiri supaya bisa menyebut murid atau baris yang sudah memakainya.
     *
     * @return array<string, list<mixed>>
     */
    public static function aturanDataDiri(): array
    {
        return [
            'nik' => ['nullable', 'digits:16'],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nama_panggilan' => ['required', 'string', 'max:50'],
            'jenis_kelamin' => ['required', Rule::enum(JenisKelamin::class)],
            'tempat_lahir' => ['required', 'string', 'max:100'],
            'tanggal_lahir' => ['bail', 'required', 'date_format:Y-m-d', 'before:today'],
            'agama' => ['required', 'string', 'max:20'],
            'alamat' => ['required', 'string', 'max:500'],
            'anak_ke' => ['nullable', 'integer', 'min:1', 'max:20'],
            'catatan_khusus' => ['nullable', 'string', 'max:1000'],
            'tanggal_masuk' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dataMurid(): array
    {
        return collect($this->validated())->except('foto')->all();
    }

    public function foto(): ?UploadedFile
    {
        $file = $this->file('foto');

        return $file instanceof UploadedFile ? $file : null;
    }
}
