<?php

namespace App\Services;

use App\Exceptions\LayananTidakTersediaException;
use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Memverifikasi ID token dari Google Identity Services: tanda tangan RS256 dengan kunci publik Google, `exp`,
 * `iss` dari Google, dan `aud` = GOOGLE_CLIENT_ID. Email di token hanya dipercaya setelah semua itu lolos.
 */
class GoogleIdTokenVerifier
{
    public const URL_KUNCI_PUBLIK = 'https://www.googleapis.com/oauth2/v3/certs';

    private const PENERBIT = ['accounts.google.com', 'https://accounts.google.com'];

    private const KUNCI_CACHE = 'google:kunci-publik';

    /** Dipakai kalau Google tidak mengirim `Cache-Control: max-age`. */
    private const MASA_CACHE_BAWAAN_DETIK = 3600;

    private const BATAS_WAKTU_UNDUH_DETIK = 5;

    private const PESAN_GOOGLE_TIDAK_TERJANGKAU = 'Login Google sedang tidak bisa diproses. Coba lagi beberapa saat lagi.';

    /**
     * @return array{sub: string, email: string, email_verified: bool}|null null kalau token tidak sah untuk aplikasi ini
     *
     * @throws LayananTidakTersediaException
     */
    public function verifikasi(string $idToken): ?array
    {
        $clientId = config('services.google.client_id');

        if (! is_string($clientId) || $clientId === '') {
            throw new LayananTidakTersediaException(
                'Login Google belum dikonfigurasi. Hubungi Kepala Sekolah.',
                'GOOGLE_CLIENT_ID belum diisi di .env; ID token Google tidak bisa diverifikasi.',
            );
        }

        $kunci = JWK::parseKeySet($this->kunciPublik());

        try {
            $klaim = JWT::decode($idToken, $kunci);
        } catch (UnexpectedValueException|DomainException) {
            // Tanda tangan salah, kedaluwarsa, kunci tidak dikenal, atau bukan JWT: semuanya berarti token tidak sah.
            return null;
        }

        $sah = isset($klaim->exp, $klaim->sub, $klaim->email)
            && in_array($klaim->iss ?? null, self::PENERBIT, true)
            && ($klaim->aud ?? null) === $clientId;

        if (! $sah) {
            return null;
        }

        return [
            'sub' => (string) $klaim->sub,
            'email' => Str::lower((string) $klaim->email),
            'email_verified' => ($klaim->email_verified ?? false) === true,
        ];
    }

    /**
     * Kunci disimpan di cache selama `max-age` dari Google, jadi tidak setiap login mengunduhnya lagi.
     *
     * @return array<string, mixed>
     *
     * @throws LayananTidakTersediaException
     */
    private function kunciPublik(): array
    {
        $tersimpan = Cache::get(self::KUNCI_CACHE);

        if (is_array($tersimpan)) {
            return $tersimpan;
        }

        try {
            $respons = Http::timeout(self::BATAS_WAKTU_UNDUH_DETIK)->get(self::URL_KUNCI_PUBLIK);
        } catch (ConnectionException $e) {
            throw new LayananTidakTersediaException(self::PESAN_GOOGLE_TIDAK_TERJANGKAU, 'Kunci publik Google tidak bisa diunduh: '.$e->getMessage(), $e);
        }

        $kunci = $respons->json();

        if (! $respons->successful() || ! is_array($kunci) || ! is_array($kunci['keys'] ?? null)) {
            throw new LayananTidakTersediaException(self::PESAN_GOOGLE_TIDAK_TERJANGKAU, "Kunci publik Google tidak valid (HTTP {$respons->status()}).");
        }

        preg_match('/max-age=(\d+)/', $respons->header('Cache-Control'), $maxAge);
        Cache::put(self::KUNCI_CACHE, $kunci, isset($maxAge[1]) ? (int) $maxAge[1] : self::MASA_CACHE_BAWAAN_DETIK);

        return $kunci;
    }
}
