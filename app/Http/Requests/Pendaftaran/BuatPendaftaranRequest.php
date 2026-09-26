<?php

namespace App\Http\Requests\Pendaftaran;

use App\Enums\Hubungan;
use App\Enums\JenisDokumen;
use App\Enums\JenisKelamin;
use App\Enums\Tingkat;
use App\Rules\NomorHp;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * `POST /pendaftaran` (multipart). Dokumen dikirim per jenis: `akta_kelahiran`, `kartu_keluarga`, dan
 * `pas_foto` wajib (gambar atau PDF; pas foto harus gambar), `lainnya[]` opsional maksimal 3 file.
 */
class BuatPendaftaranRequest extends FormRequest
{
    private const MAKSIMAL_DOKUMEN_LAINNYA = 3;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hubungan' => ['required', Rule::enum(Hubungan::class)],
            'tingkat_tujuan' => ['required', Rule::enum(Tingkat::class)],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nama_panggilan' => ['required', 'string', 'max:50'],
            'jenis_kelamin' => ['required', Rule::enum(JenisKelamin::class)],
            'tempat_lahir' => ['required', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date_format:Y-m-d', 'before:today'],
            'nik' => ['required', 'digits:16'],
            'agama' => ['required', 'string', 'max:20'],
            'alamat' => ['required', 'string', 'max:500'],
            'nama_ayah' => ['nullable', 'string', 'max:255'],
            'pekerjaan_ayah' => ['nullable', 'string', 'max:255'],
            'nama_ibu' => ['nullable', 'string', 'max:255'],
            'pekerjaan_ibu' => ['nullable', 'string', 'max:255'],
            'no_hp' => ['required', 'string', new NomorHp],
            JenisDokumen::AktaKelahiran->value => ['required', ...MediaService::aturanDokumen()],
            JenisDokumen::KartuKeluarga->value => ['required', ...MediaService::aturanDokumen()],
            JenisDokumen::PasFoto->value => ['required', ...MediaService::aturanGambar()],
            JenisDokumen::Lainnya->value => ['sometimes', 'array', 'max:'.self::MAKSIMAL_DOKUMEN_LAINNYA],
            JenisDokumen::Lainnya->value.'.*' => ['required', ...MediaService::aturanDokumen()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'akta_kelahiran' => 'akta kelahiran',
            'kartu_keluarga' => 'kartu keluarga',
            'pas_foto' => 'pas foto',
            'lainnya.*' => 'dokumen lainnya',
            'tingkat_tujuan' => 'kelompok tujuan',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dataPendaftaran(): array
    {
        return collect($this->validated())->except(array_column(JenisDokumen::cases(), 'value'))->all();
    }

    /**
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public function dokumen(): array
    {
        $dokumen = [];

        foreach (JenisDokumen::cases() as $jenis) {
            $file = $this->file($jenis->value);

            if ($file instanceof UploadedFile) {
                $dokumen[$jenis->value] = $file;
            } elseif (is_array($file)) {
                $dokumen[$jenis->value] = array_values($file);
            }
        }

        return $dokumen;
    }
}
