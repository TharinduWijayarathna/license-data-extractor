<?php

/**
 * Application routes.
 *
 * @var \flight\net\Router $router
 * @var \flight\Engine $app
 * @var \App\Utils\Config $config
 */

use App\Controller\LicenceController;
use App\Controller\PostController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Utils\DatabaseFactory;
use flight\net\Router;

$router->group('', function (Router $router) use ($config) {
    $router->group('/', function (Router $router) {
        $router->get('', [LicenceController::class, 'index']);
        $router->post('/upload', [LicenceController::class, 'upload']);
        $router->post('/process', [LicenceController::class, 'process']);
        $router->get('/download', [LicenceController::class, 'download']);
        $router->post('/jobs/@id:[0-9]+/retry', [LicenceController::class, 'retry']);
        $router->get('/status', [LicenceController::class, 'status']);
    }, [CsrfMiddleware::class]);

    // Posts demo requires SimplePdo (PostController). Skip when DB is disabled.
    if (DatabaseFactory::isEnabled($config)) {
        $router->get('/posts', [PostController::class, 'index']);
        $router->get('/posts/@id:[0-9]+', [PostController::class, 'show']);

        $router->group('/api', function (Router $router) {
            $router->get('/posts', [PostController::class, 'apiIndex']);
            $router->get('/posts/@id:[0-9]+', [PostController::class, 'apiShow']);
        });
    }
}, [SecurityHeadersMiddleware::class]);
