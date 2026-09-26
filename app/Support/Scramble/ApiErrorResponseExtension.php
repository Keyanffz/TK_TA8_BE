<?php

namespace App\Support\Scramble;

use App\Enums\KodeError;
use App\Exceptions\AksesAkunDitolakException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\LayananBelumDikonfigurasiException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Bentuk error bawaan Scramble adalah `{ message, errors }`. FE membuat tipe dari api.json,
 * jadi dokumentasi harus memakai format error A7 yang benar-benar dikirim ApiExceptionRenderer.
 * Extension yang didaftarkan belakangan menang atas extension bawaan Scramble.
 */
class ApiErrorResponseExtension extends ExceptionToResponseExtension
{
    private const AKUN_TIDAK_AKTIF = [KodeError::AccountPending, KodeError::AccountRejected, KodeError::AccountInactive];

    /** @var array<class-string, non-empty-list<KodeError>> */
    private const KODE_PER_EXCEPTION = [
        ValidationException::class => [KodeError::ValidationError],
        BusinessRuleException::class => [KodeError::BusinessRule],
        AksesAkunDitolakException::class => self::AKUN_TIDAK_AKTIF,
        AuthenticationException::class => [KodeError::Unauthenticated],
        AuthorizationException::class => [KodeError::Forbidden],
        AccessDeniedHttpException::class => [KodeError::Forbidden],
        RecordsNotFoundException::class => [KodeError::NotFound],
        NotFoundHttpException::class => [KodeError::NotFound],
        TooManyRequestsHttpException::class => [KodeError::TooManyRequests],
        LayananBelumDikonfigurasiException::class => [KodeError::ServerError],
    ];

    /** @var array<class-string, int> Exception yang statusnya berbeda dari status bawaan kodenya. */
    private const STATUS_PER_EXCEPTION = [
        LayananBelumDikonfigurasiException::class => LayananBelumDikonfigurasiException::STATUS,
    ];

    public function shouldHandle(Type $type): bool
    {
        return $this->kodeUntuk($type) !== null;
    }

    public function toResponse(Type $type): ?Response
    {
        $kode = $this->kodeUntuk($type);

        if ($kode === null) {
            return null;
        }

        $status = null;
        foreach (self::STATUS_PER_EXCEPTION as $class => $statusKhusus) {
            if ($type instanceof ObjectType && $type->isInstanceOf($class)) {
                $status = $statusKhusus;
            }
        }

        return SkemaErrorA7::respons($kode, implode(' / ', array_map(fn (KodeError $k) => $k->value, $kode)), $status);
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', Str::start($type->name, '\\'), $this->components);
    }

    /**
     * @return non-empty-list<KodeError>|null
     */
    private function kodeUntuk(Type $type): ?array
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        foreach (self::KODE_PER_EXCEPTION as $class => $kode) {
            if ($type->isInstanceOf($class)) {
                return $kode;
            }
        }

        return null;
    }
}
