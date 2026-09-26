<?php

use Illuminate\Support\Carbon;

it('mengembalikan status ok dengan waktu berzona +07:00 tanpa token', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-26 08:30:00', 'Asia/Jakarta'));

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Berhasil',
            'data' => ['status' => 'ok', 'time' => '2026-09-26T08:30:00+07:00'],
            'meta' => null,
        ]);
});
