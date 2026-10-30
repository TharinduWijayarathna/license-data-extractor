<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\CsrfMiddleware;
use App\Utils\Csrf;
use flight\Engine;
use flight\Session;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;

class CsrfMiddlewareTest extends TestCase
{
    public function testTokenIsStablePerSession(): void
    {
        $session = new Session(['test_mode' => true, 'auto_commit' => false]);
        $token = Csrf::token($session);

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, Csrf::token($session));
        $this->assertTrue(Csrf::isValid($session, $token));
        $this->assertFalse(Csrf::isValid($session, 'wrong'));
        $this->assertFalse(Csrf::isValid($session, null));
    }

    public function testGetRequestsPassThrough(): void
    {
        $halts = [];
        $app = $this->engine('GET', [], $halts);

        (new CsrfMiddleware($app, new Session(['test_mode' => true, 'auto_commit' => false])))->before([]);

        $this->assertSame([], $halts);
    }

    public function testPostWithValidTokenPasses(): void
    {
        $session = new Session(['test_mode' => true, 'auto_commit' => false]);
        $halts = [];
        $app = $this->engine('POST', ['csrf_token' => Csrf::token($session)], $halts);

        (new CsrfMiddleware($app, $session))->before([]);

        $this->assertSame([], $halts);
    }

    public function testPostWithoutTokenIsRejected(): void
    {
        $session = new Session(['test_mode' => true, 'auto_commit' => false]);
        Csrf::token($session);
        $halts = [];
        $app = $this->engine('POST', ['csrf_token' => 'forged'], $halts);

        (new CsrfMiddleware($app, $session))->before([]);

        $this->assertSame([403], $halts);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,int>      $halts Receives the status code of each halt() call
     * @return Engine
     */
    private function engine(string $method, array $data, array &$halts): Engine
    {
        $app = new Engine();
        $app->request()->method = $method;
        $app->request()->data = new Collection($data);
        $app->map('halt', function (int $code = 200) use (&$halts): void {
            $halts[] = $code;
        });

        return $app;
    }
}
