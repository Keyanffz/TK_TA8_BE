<?php

namespace App\Http\Requests\Pembayaran;

use App\Enums\MetodeBayar;
use App\Enums\Role;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * `POST /tagihan/{id}/pembayaran`. Wali murid selalu transfer dan wajib mengirim bukti (multipart). Petugas
 * keuangan mencatat pembayaran tunai atau transfer yang sudah masuk ke rekening sekolah; untuk transfer,
 * bukti, bank, dan nama pengirim boleh dikosongkan. Jumlah tidak dikirim karena selalu sama dengan total tagihan.
 */
class BayarTagihanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->dariWali()) {
            return [
                'bukti' => ['required', ...MediaService::aturanGambar()],
                'tanggal_bayar' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'bank_pengirim' => ['required', 'string', 'max:50'],
                'nama_pengirim' => ['required', 'string', 'max:100'],
            ];
        }

        $hanyaTransfer = 'prohibited_unless:metode,'.MetodeBayar::Transfer->value;

        return [
            'metode' => ['required', Rule::enum(MetodeBayar::class)],
            'tanggal_bayar' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'bukti' => ['nullable', $hanyaTransfer, ...MediaService::aturanGambar()],
            'bank_pengirim' => ['nullable', $hanyaTransfer, 'string', 'max:50'],
            'nama_pengirim' => ['nullable', $hanyaTransfer, 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bukti.prohibited_unless' => 'Bukti hanya dilampirkan untuk pembayaran transfer.',
            'bank_pengirim.prohibited_unless' => 'Bank pengirim hanya diisi untuk pembayaran transfer.',
            'nama_pengirim.prohibited_unless' => 'Nama pengirim hanya diisi untuk pembayaran transfer.',
        ];
    }

    public function dariWali(): bool
    {
        return $this->user()?->role === Role::WaliMurid;
    }

    public function metode(): MetodeBayar
    {
        return $this->dariWali() ? MetodeBayar::Transfer : $this->enum('metode', MetodeBayar::class) ?? throw new LogicException('Metode sudah divalidasi wajib ada.');
    }

    public function bukti(): ?UploadedFile
    {
        $file = $this->file('bukti');

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * @return array{tanggal_bayar: string, bank_pengirim: ?string, nama_pengirim: ?string}
     */
    public function dataPembayaran(): array
    {
        return [
            'tanggal_bayar' => $this->string('tanggal_bayar')->toString(),
            'bank_pengirim' => $this->filled('bank_pengirim') ? $this->string('bank_pengirim')->trim()->toString() : null,
            'nama_pengirim' => $this->filled('nama_pengirim') ? $this->string('nama_pengirim')->trim()->toString() : null,
        ];
    }
}
