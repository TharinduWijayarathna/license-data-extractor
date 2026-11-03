<?php

/**
 * Application routes.
 *
 * @var \flight\net\Router $router
 * @var \flight\Engine $app
 * @var \App\Utils\Config $config
 */

use App\Controller\ApiController;
use App\Controller\LicenceController;
use App\Middleware\ApiKeyMiddleware;
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

    // REST API for automations (API key required) — see docs/API.md
    $router->group('/api/v1', function (Router $router) {
        $router->post('/documents', [ApiController::class, 'upload']);
        $router->post('/process', [ApiController::class, 'process']);
        $router->get('/status', [ApiController::class, 'status']);
        $router->get('/workbook', [ApiController::class, 'download']);
    }, [ApiKeyMiddleware::class]);
}, [SecurityHeadersMiddleware::class]);
