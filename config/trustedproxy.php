<?php

return [
    /*
     * Proxy yang dipercaya untuk header X-Forwarded-* (dibaca middleware TrustProxies bawaan Laravel).
     * Isi dengan IP/CIDR reverse proxy dipisah koma, atau `*` kalau aplikasi hanya bisa diakses lewat proxy.
     * Kosong = tidak ada proxy yang dipercaya. Signed URL file private dibentuk dari host dan skema request,
     * jadi di balik proxy HTTPS nilai ini harus diisi.
     */
    'proxies' => env('TRUSTED_PROXIES'),
];
