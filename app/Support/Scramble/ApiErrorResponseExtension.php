<?php

namespace App\Support\Scramble;

use App\Enums\KodeError;
use App\Exceptions\BusinessRuleException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
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
    private const KODE_PER_EXCEPTION = [
        ValidationException::class => KodeError::ValidationError,
        BusinessRuleException::class => KodeError::BusinessRule,
        AuthenticationException::class => KodeError::Unauthenticated,
        AuthorizationException::class => KodeError::Forbidden,
        AccessDeniedHttpException::class => KodeError::Forbidden,
        RecordsNotFoundException::class => KodeError::NotFound,
        NotFoundHttpException::class => KodeError::NotFound,
        TooManyRequestsHttpException::class => KodeError::TooManyRequests,
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

        $errors = $kode === KodeError::ValidationError
            ? (new OpenApi\ObjectType)->additionalProperties((new OpenApi\ArrayType)->setItems(new OpenApi\StringType))
            : new OpenApi\NullType;

        $body = (new OpenApi\ObjectType)
            ->addProperty('success', (new OpenApi\BooleanType)->const(false))
            ->addProperty('message', new OpenApi\StringType)
            ->addProperty('code', (new OpenApi\StringType)->const($kode->value))
            ->addProperty('errors', $errors)
            ->setRequired(['success', 'message', 'code', 'errors']);

        return Response::make($kode->status())
            ->setDescription($kode->value)
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', Str::start($type->name, '\\'), $this->components);
    }

    private function kodeUntuk(Type $type): ?KodeError
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
