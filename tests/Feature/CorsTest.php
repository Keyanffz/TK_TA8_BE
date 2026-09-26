<?php

it('mengizinkan preflight dari FRONTEND_URL dengan header Authorization', function () {
    $frontend = config('cors.allowed_origins')[0];

    $this->call('OPTIONS', '/api/v1/health', server: [
        'HTTP_ORIGIN' => $frontend,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', $frontend)
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
});

it('tidak memberi izin CORS ke origin lain', function () {
    $response = $this->getJson('/api/v1/health', ['Origin' => 'https://situs-lain.test'])->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://situs-lain.test');
});
