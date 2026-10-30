<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\Csrf;
use flight\Engine;
use flight\Session;

/**
 * Rejects POST requests without the session's CSRF token (form field "csrf_token").
 * Attach to route groups that contain state-changing forms.
 */
class CsrfMiddleware
{
    /** @var Engine */
    private $app;

    /** @var Session */
    private $session;

    /**
     * @param Engine $app
     */
    public function __construct(Engine $app, Session $session)
    {
        $this->app = $app;
        $this->session = $session;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function before(array $params): void
    {
        $request = $this->app->request();
        if ($request->method !== 'POST') {
            return;
        }

        if (!Csrf::isValid($this->session, $request->data[Csrf::FIELD] ?? null)) {
            $this->app->halt(403, 'Invalid or expired form token. Go back, reload the page and try again.');
        }
    }
}
