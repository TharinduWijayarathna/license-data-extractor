<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\ApiKeyMiddleware;
use App\Utils\Config;
use flight\Engine;
use PHPUnit\Framework\TestCase;

class ApiKeyMiddlewareTest extends TestCase
{
    private const KEY = 'k_0123456789abcdef0123456789abcdef';

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testValidHeaderKeyPasses(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = self::KEY;

        $this->assertSame([], $this->runMiddleware(self::KEY));
    }

    public function testAnyOfSeveralKeysPasses(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = 'second-key';

        $this->assertSame([], $this->runMiddleware(self::KEY . ', second-key'));
    }

    public function testBearerTokenPasses(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::KEY;

        $this->assertSame([], $this->runMiddleware(self::KEY));
    }

    public function testMissingKeyIsRejected(): void
    {
        $halts = $this->runMiddleware(self::KEY);

        $this->assertSame(401, $halts[0][1]);
        $this->assertSame('unauthorized', $halts[0][0]['error']);
    }

    public function testWrongKeyIsRejected(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = 'nope';

        $this->assertSame(401, $this->runMiddleware(self::KEY)[0][1]);
    }

    public function testApiIsDisabledWithoutConfiguredKeys(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = '';

        $halts = $this->runMiddleware(' , ');

        $this->assertSame(503, $halts[0][1]);
        $this->assertSame('api_disabled', $halts[0][0]['error']);
    }

    public function testConfiguredKeysAcceptListsAndStrings(): void
    {
        $this->assertSame(['a', 'b'], ApiKeyMiddleware::configuredKeys(new Config(['api' => ['keys' => ' a ,,b ']])));
        $this->assertSame(['a'], ApiKeyMiddleware::configuredKeys(new Config(['api' => ['keys' => ['a', '']]])));
        $this->assertSame([], ApiKeyMiddleware::configuredKeys(new Config([])));
    }

    /**
     * Run the middleware; returns each jsonHalt() call as [data, status].
     *
     * @return array<int,array{0:array<string,string>,1:int}>
     */
    private function runMiddleware(string $keys): array
    {
        $halts = [];
        $app = new Engine();
        $app->map('jsonHalt', function (array $data, int $code = 200) use (&$halts): void {
            $halts[] = [$data, $code];
        });

        (new ApiKeyMiddleware($app, new Config(['api' => ['keys' => $keys]])))->before([]);

        $this->assertSame('no-store', $app->response()->getHeader('Cache-Control'));

        return $halts;
    }
}
