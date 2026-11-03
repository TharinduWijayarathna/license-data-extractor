<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\Config;
use flight\Engine;

/**
 * Gate for the /api routes: the request must carry one of the configured keys
 * (config api.keys / env API_KEYS) in the X-API-Key header, or as
 * "Authorization: Bearer <key>". With no keys configured the API is disabled.
 */
class ApiKeyMiddleware
{
    public const HEADER = 'X-API-Key';

    /** @var Engine */
    private $app;

    /** @var Config */
    private $config;

    /**
     * @param Engine $app
     */
    public function __construct(Engine $app, Config $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function before(array $params): void
    {
        // API responses (files, job states) must never be cached by proxies
        $this->app->response()->header('Cache-Control', 'no-store');

        $keys = self::configuredKeys($this->config);
        if ($keys === []) {
            $this->app->jsonHalt([
                'error' => 'api_disabled',
                'message' => 'The API is disabled because no API keys are configured on the server (API_KEYS).',
            ], 503);
            return;
        }

        $presented = $this->presentedKey();
        foreach ($keys as $key) {
            // Constant-time comparison so response timing does not leak the key
            if ($presented !== '' && hash_equals($key, $presented)) {
                return;
            }
        }

        $this->app->jsonHalt([
            'error' => 'unauthorized',
            'message' => 'Missing or invalid API key. Send it in the ' . self::HEADER . ' header.',
        ], 401);
    }

    /**
     * Keys from config: a comma-separated string (from .env) or a list.
     *
     * @return array<int,string>
     */
    public static function configuredKeys(Config $config): array
    {
        $raw = $config->get('api.keys', '');
        $keys = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');

        $clean = [];
        foreach ($keys as $key) {
            $key = is_scalar($key) ? trim((string) $key) : '';
            if ($key !== '') {
                $clean[] = $key;
            }
        }

        return $clean;
    }

    private function presentedKey(): string
    {
        $request = $this->app->request();

        $key = trim($request->getHeader(self::HEADER));
        if ($key !== '') {
            return $key;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($request->getHeader('Authorization')), $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
