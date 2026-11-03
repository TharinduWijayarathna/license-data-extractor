<?php

/**
 * Application routes.
 *
 * @var \flight\net\Router $router
 * @var \flight\Engine $app
 * @var \App\Utils\Config $config
 */

use App\Controller\LicenceController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use flight\net\Router;

$router->group('', function (Router $router) {
    // Dashboard (HTML forms, CSRF-protected)
    $router->group('/', function (Router $router) {
        $router->get('', [LicenceController::class, 'index']);
        $router->post('/upload', [LicenceController::class, 'upload']);
        $router->post('/process', [LicenceController::class, 'process']);
        $router->get('/download', [LicenceController::class, 'download']);
        $router->post('/jobs/@id:[0-9]+/retry', [LicenceController::class, 'retry']);
        $router->get('/status', [LicenceController::class, 'status']);
    }, [CsrfMiddleware::class]);
}, [SecurityHeadersMiddleware::class]);
