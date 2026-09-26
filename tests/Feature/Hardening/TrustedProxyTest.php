<?php

use App\Models\Murid;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->kepsek = buatKepalaSekolah();
    $this->murid = Murid::factory()->create(['foto_path' => 'murid/aisyah.jpg']);
});

function ambilDariProxy(object $test, int $muridId): string
{
    return (string) $test->actingAs($test->kepsek)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'api.tkta8.sch.id', 'X-Forwarded-Port' => '443'])
        ->getJson("/api/v1/murid/{$muridId}")
        ->assertOk()
        ->json('data.foto_url');
}

it('membentuk signed URL dari host dan skema proxy yang dipercaya', function () {
    config(['trustedproxy.proxies' => '10.0.0.5']);

    expect(ambilDariProxy($this, $this->murid->id))->toStartWith('https://api.tkta8.sch.id/api/v1/media/');
});

it('mengabaikan header X-Forwarded dari proxy yang tidak dipercaya', function () {
    config(['trustedproxy.proxies' => null]);

    expect(ambilDariProxy($this, $this->murid->id))->not->toContain('api.tkta8.sch.id');
});
