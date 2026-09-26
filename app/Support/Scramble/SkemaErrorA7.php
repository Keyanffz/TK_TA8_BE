<?php

namespace App\Support\Scramble;

use App\Enums\KodeError;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;

/**
 * Skema OpenAPI untuk respons error A7 `{ success, message, code, errors }`.
 */
final class SkemaErrorA7
{
    /**
     * @param  non-empty-list<KodeError>  $kode  semua kode harus berstatus HTTP sama
     */
    public static function respons(array $kode, string $deskripsi): Response
    {
        $kodeValidasi = in_array(KodeError::ValidationError, $kode, true);

        $errors = $kodeValidasi
            ? (new OpenApi\ObjectType)->additionalProperties((new OpenApi\ArrayType)->setItems(new OpenApi\StringType))
            : new OpenApi\NullType;

        $body = (new OpenApi\ObjectType)
            ->addProperty('success', (new OpenApi\BooleanType)->const(false))
            ->addProperty('message', new OpenApi\StringType)
            ->addProperty('code', (new OpenApi\StringType)->enum(array_map(fn (KodeError $k): string => $k->value, $kode)))
            ->addProperty('errors', $errors)
            ->setRequired(['success', 'message', 'code', 'errors']);

        return Response::make($kode[0]->status())
            ->setDescription($deskripsi)
            ->setContent('application/json', Schema::fromType($body));
    }
}
