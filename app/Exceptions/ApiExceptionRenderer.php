<?php

namespace App\Exceptions;

use App\Enums\KodeError;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Mengubah semua exception di route API menjadi format error A7.
 * Didaftarkan di bootstrap/app.php; exception tetap dilaporkan ke log oleh handler Laravel.
 */
final class ApiExceptionRenderer
{
    public const PESAN_VALIDASI = 'Data tidak valid';

    public const PESAN_TIDAK_TERAUTENTIKASI = 'Sesi login tidak ditemukan atau sudah berakhir. Silakan masuk kembali.';

    public const PESAN_TIDAK_BERHAK = 'Anda tidak punya akses untuk tindakan ini.';

    public const PESAN_DATA_TIDAK_ADA = 'Data tidak ditemukan.';

    public const PESAN_ENDPOINT_TIDAK_ADA = 'Endpoint tidak ditemukan. Periksa kembali alamat dan metode HTTP.';

    public const PESAN_TAUTAN_FILE_TIDAK_BERLAKU = 'Tautan file sudah kedaluwarsa atau tidak valid. Muat ulang halaman untuk mendapatkan tautan baru.';

    public const PESAN_PARAMETER_DAFTAR_TIDAK_DIKENAL = 'Parameter filter atau urutan tidak dikenal. Periksa kembali parameter filter dan sort.';

    public const PESAN_UNGGAHAN_TERLALU_BESAR = 'Ukuran file terlalu besar. Maksimal 5 MB per file.';

    public const PESAN_SERVER = 'Server sedang mengalami gangguan. Coba lagi beberapa saat lagi; jika masih gagal, hubungi pihak sekolah.';

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(self::PESAN_VALIDASI, KodeError::ValidationError, errors: $e->errors()),
            $e instanceof AuthenticationException => ApiResponse::error(self::PESAN_TIDAK_TERAUTENTIKASI, KodeError::Unauthenticated),
            $e instanceof BusinessRuleException => ApiResponse::error($e->getMessage(), KodeError::BusinessRule),
            $e instanceof AksesAkunDitolakException => ApiResponse::error($e->getMessage(), $e->kode),
            $e instanceof InvalidSignatureException => ApiResponse::error(self::PESAN_TAUTAN_FILE_TIDAK_BERLAKU, KodeError::Forbidden),
            $e instanceof InvalidQuery => ApiResponse::error(self::PESAN_PARAMETER_DAFTAR_TIDAK_DIKENAL, KodeError::ValidationError),
            $e instanceof HttpExceptionInterface => $this->dariHttpException($e, $request),
            default => ApiResponse::error(self::PESAN_SERVER, KodeError::ServerError),
        };
    }

    /**
     * Status di luar daftar A7 dipetakan ke kode A7 terdekat: 405 dianggap 404,
     * 4xx lain (misalnya 413 unggahan terlalu besar) dianggap VALIDATION_ERROR.
     */
    private function dariHttpException(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();
        $headers = $e->getHeaders();

        return match (true) {
            $status === 401 => ApiResponse::error(self::PESAN_TIDAK_TERAUTENTIKASI, KodeError::Unauthenticated, headers: $headers),
            $status === 403 => ApiResponse::error(self::PESAN_TIDAK_BERHAK, KodeError::Forbidden, headers: $headers),
            $status === 404 => ApiResponse::error(
                $request->route() === null ? self::PESAN_ENDPOINT_TIDAK_ADA : self::PESAN_DATA_TIDAK_ADA,
                KodeError::NotFound,
            ),
            $status === 405 => ApiResponse::error(self::PESAN_ENDPOINT_TIDAK_ADA, KodeError::NotFound),
            $status === 413 => ApiResponse::error(self::PESAN_UNGGAHAN_TERLALU_BESAR, KodeError::ValidationError),
            $status === 429 => ApiResponse::error(
                sprintf('Terlalu banyak percobaan. Coba lagi dalam %d detik.', (int) ($headers['Retry-After'] ?? 60)),
                KodeError::TooManyRequests,
                headers: $headers,
            ),
            $status === 503 => ApiResponse::error('Layanan sedang dalam pemeliharaan. Coba lagi beberapa saat lagi.', KodeError::ServerError, 503, headers: $headers),
            $status >= 500 => ApiResponse::error(self::PESAN_SERVER, KodeError::ServerError, $status),
            default => ApiResponse::error('Permintaan tidak dapat diproses. Periksa kembali data yang dikirim.', KodeError::ValidationError),
        };
    }
}
