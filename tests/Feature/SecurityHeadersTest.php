<?php

declare(strict_types=1);

it('sends the §14 header set on every public page', function (): void {
    $response = $this->get('/en')->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("object-src 'none'");
});

it('sends HSTS only over HTTPS', function (): void {
    $this->get('/en')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/en')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('covers the admin and the API too', function (): void {
    $this->get('/admin/login')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->getJson('/api/calendar')->assertHeader('X-Content-Type-Options', 'nosniff');
});
