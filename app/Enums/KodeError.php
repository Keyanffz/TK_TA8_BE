<?php

namespace App\Enums;

/**
 * Kode error di respons gagal (kontrak A7) beserta status HTTP bawaannya.
 */
enum KodeError: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case AccountInactive = 'ACCOUNT_INACTIVE';
    case PasswordWajibDiganti = 'PASSWORD_WAJIB_DIGANTI';
    case NotFound = 'NOT_FOUND';
    case ValidationError = 'VALIDATION_ERROR';
    case BusinessRule = 'BUSINESS_RULE';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,
            self::Forbidden, self::AccountInactive, self::PasswordWajibDiganti => 403,
            self::NotFound => 404,
            self::ValidationError, self::BusinessRule => 422,
            self::TooManyRequests => 429,
            self::ServerError => 500,
        };
    }
}
