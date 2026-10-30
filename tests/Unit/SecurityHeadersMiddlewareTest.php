<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\SecurityHeadersMiddleware;
use flight\Engine;
use PHPUnit\Framework\TestCase;

class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testBeforeSetsSecurityHeaders(): void
    {
        $app = new Engine();
        $app->set('csp_nonce', 'abc123nonce');

        $middleware = new SecurityHeadersMiddleware($app);
        $middleware->before([]);

        $headers = $app->response()->getHeaders();
        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $csp = $headers['Content-Security-Policy'];
        $this->assertIsString($csp);
        $this->assertStringContainsString('nonce-abc123nonce', $csp);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertArrayHasKey('Strict-Transport-Security', $headers);
    }
}
