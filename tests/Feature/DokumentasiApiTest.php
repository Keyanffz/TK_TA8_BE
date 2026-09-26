<?php

it('membuka /docs/api di environment selain production', function () {
    $this->get('/docs/api')->assertOk();
});

it('menutup /docs/api di production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/docs/api')->assertForbidden();
});

/**
 * @return list<string>
 */
function kodeErrorTerdokumentasi(array $operasi, int $status): array
{
    return $operasi['responses'][$status]['content']['application/json']['schema']['properties']['code']['enum'] ?? [];
}

it('mendokumentasikan auth Bearer dan format error A7', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();
    $me = $dokumen['paths']['/auth/me']['get'];

    expect($dokumen['servers'][0]['url'])->toEndWith('/api/v1')
        ->and($dokumen['components']['securitySchemes'])->toContain(['type' => 'http', 'scheme' => 'bearer'])
        ->and($dokumen['paths']['/health']['get']['security'])->toBe([])
        ->and($dokumen['paths']['/health']['get']['responses'])->toHaveKeys([200])
        ->and($me['responses'][401]['content']['application/json']['schema']['required'])->toBe(['success', 'message', 'code', 'errors'])
        ->and(kodeErrorTerdokumentasi($me, 401))->toBe(['UNAUTHENTICATED']);
});

it('mendokumentasikan respons 403 dari middleware role dan status akun', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();

    expect(kodeErrorTerdokumentasi($dokumen['paths']['/auth/me']['get'], 403))
        ->toBe(['ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/guru/{id}']['put'], 403))
        ->toBe(['FORBIDDEN', 'ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/guru/{id}']['put'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/auth/login']['post'], 403))
        ->toBe(['ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/wali/tautkan-anak']['post'], 429))->toBe(['TOO_MANY_REQUESTS'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/auth/google']['post'], 503))->toBe(['SERVER_ERROR'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/media/{token}']['get'], 403))->toBe(['FORBIDDEN']);
});

it('mendokumentasikan 404 untuk data di luar jangkauan pengguna', function () {
    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();

    expect(kodeErrorTerdokumentasi($dokumen['paths']['/murid/{id}']['get'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/kelas/{id}']['get'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/tagihan/{id}']['get'], 404))->toBe(['NOT_FOUND'])
        ->and(kodeErrorTerdokumentasi($dokumen['paths']['/murid']['get'], 403))->not->toContain('FORBIDDEN');
});
