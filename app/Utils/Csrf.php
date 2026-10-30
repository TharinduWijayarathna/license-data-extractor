<?php

declare(strict_types=1);

namespace App\Utils;

use flight\Session;

/**
 * Per-session CSRF token (synchronizer token pattern).
 *
 * Templates call {{ csrf_token() }} (registered in services.php);
 * App\Middleware\CsrfMiddleware verifies it on state-changing requests.
 */
final class Csrf
{
    public const SESSION_KEY = 'csrf_token';

    public const FIELD = 'csrf_token';

    public static function token(Session $session): string
    {
        $token = $session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * @param mixed $submitted
     */
    public static function isValid(Session $session, $submitted): bool
    {
        $expected = $session->get(self::SESSION_KEY);

        return is_string($expected) && $expected !== ''
            && is_string($submitted) && hash_equals($expected, $submitted);
    }
}
