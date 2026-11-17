<?php

namespace App\Http\Requests\Pendaftaran;

use App\Enums\Agama;
use App\Enums\Hubungan;
use App\Enums\JenisKelamin;
use App\Enums\Tingkat;
use App\Http\Requests\Concerns\MemeriksaFormulirMurid;
use App\Support\FormulirMurid;
use App\Support\KelengkapanFormulir;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /pendaftaran` dan `POST /public/pendaftaran`. Tanpa unggahan; Kartu Keluarga dan akta kelahiran diserahkan
 * ke sekolah. Formulir PPDB online wajib lengkap (aturan wajib di `KelengkapanFormulir`) dan wajib menyetujui
 * pernyataan bahwa data benar; waktu kirimnya `created_at`. `hubungan` hanya untuk wali yang login: hubungan akun
 * itu dengan anak, dipakai saat menautkan anak yang diterima.
 */
class BuatPendaftaranRequest extends FormRequest
{
    use MemeriksaFormulirMurid;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hubungan' => $this->user() === null ? ['exclude'] : ['required', Rule::enum(Hubungan::class)],
            'tingkat_tujuan' => ['required', Rule::enum(Tingkat::class)],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nama_panggilan' => ['required', 'string', 'max:50'],
            'jenis_kelamin' => ['required', Rule::enum(JenisKelamin::class)],
            'tempat_lahir' => ['required', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date_format:Y-m-d', 'before:today'],
            'nik' => ['required', 'digits:16'],
            'agama' => ['required', Rule::enum(Agama::class)],
            'alamat' => ['required', 'string', 'max:500'],
            'anak_ke' => ['nullable', 'integer', 'min:1', 'max:20'],
            ...FormulirMurid::aturanAnak(),
            ...FormulirMurid::aturanOrangTua(),
            'pernyataan' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['tingkat_tujuan' => 'kelompok tujuan', ...FormulirMurid::atribut()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['pernyataan.accepted' => 'Centang pernyataan bahwa data yang diisi benar sebelum mengirim pendaftaran.'];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->tambahkanGalat($validator, KelengkapanFormulir::kurang($this->all(), $this->isianOrangTua()))];
    }

    /**
     * @return array<string, mixed>
     */
    public function dataPendaftaran(): array
    {
        return Arr::except($this->validated(), 'pernyataan');
    }
}
