<?php

namespace App\Services;

use App\Exceptions\LayananBelumDikonfigurasiException;
use Google\Client as GoogleClient;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Memverifikasi ID token dari Google Identity Services (tanda tangan, masa berlaku, issuer, dan
 * `aud` = GOOGLE_CLIENT_ID). Dipisah dari GoogleLoginService supaya test bisa menggantinya tanpa
 * menghubungi server Google.
 */
class GoogleIdTokenVerifier
{
    /**
     * @return array{sub: string, email: string, name: string, email_verified: bool}|null null jika token tidak valid
     *
     * @throws LayananBelumDikonfigurasiException jika GOOGLE_CLIENT_ID kosong
     */
    public function verifikasi(string $idToken): ?array
    {
        $clientId = config('services.google.client_id');

        if (blank($clientId)) {
            throw new LayananBelumDikonfigurasiException(
                'Login Google belum dikonfigurasi. Hubungi pihak sekolah.',
                'GOOGLE_CLIENT_ID belum diisi di .env; login Google tidak bisa diverifikasi.',
            );
        }

        try {
            $payload = (new GoogleClient(['client_id' => $clientId]))->verifyIdToken($idToken);
        } catch (UnexpectedValueException) {
            // Token yang bukan JWT utuh dilempar firebase/php-jwt sebagai exception, bukan false.
            return null;
        }

        if (! is_array($payload) || ! isset($payload['sub'], $payload['email'])) {
            return null;
        }

        return [
            'sub' => (string) $payload['sub'],
            'email' => Str::lower((string) $payload['email']),
            'name' => (string) ($payload['name'] ?? $payload['email']),
            'email_verified' => filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
