<?php

namespace App\Http\Requests\Pembayaran;

use App\Enums\MetodeBayar;
use App\Enums\Role;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * `POST /tagihan/{id}/pembayaran`. Wali murid mengirim bukti transfer (multipart); petugas keuangan mencatat
 * pembayaran tunai dengan `metode: tunai`. Jumlah tidak dikirim karena selalu sama dengan total tagihan.
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

        return [
            'metode' => ['required', Rule::enum(MetodeBayar::class)->only(MetodeBayar::Tunai)],
            'tanggal_bayar' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'metode.enum' => 'Petugas keuangan hanya mencatat pembayaran tunai. Pembayaran transfer diunggah oleh wali murid.',
        ];
    }

    public function dariWali(): bool
    {
        return $this->user()?->role === Role::WaliMurid;
    }

    public function bukti(): UploadedFile
    {
        $file = $this->file('bukti');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Bukti transfer sudah divalidasi wajib ada.');
    }

    public function tanggalBayar(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('tanggal_bayar')->toString())->startOfDay();
    }

    /**
     * @return array{tanggal_bayar: string, bank_pengirim: string, nama_pengirim: string}
     */
    public function dataTransfer(): array
    {
        return [
            'tanggal_bayar' => $this->string('tanggal_bayar')->toString(),
            'bank_pengirim' => $this->string('bank_pengirim')->trim()->toString(),
            'nama_pengirim' => $this->string('nama_pengirim')->trim()->toString(),
        ];
    }
}
