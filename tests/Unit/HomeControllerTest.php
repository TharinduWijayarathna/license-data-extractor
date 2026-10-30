<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utils\Config;
use App\Controller\HomeController;
use flight\Engine;
use PHPUnit\Framework\TestCase;

class HomeControllerTest extends TestCase
{
    public function testIndexRendersWelcomeViaEngine(): void
    {
        // render() is a mappable Engine method: capture it instead of mocking
        $rendered = [];
        $app = new Engine();
        $app->map('render', function (string $template, array $data = []) use (&$rendered): void {
            $rendered[] = [$template, $data];
        });

        $config = new Config([
            'app' => [
                'env' => 'testing',
                'debug' => true,
            ],
        ]);

        $controller = new HomeController($app, $config);
        $controller->index();

        $this->assertCount(1, $rendered);
        [$template, $data] = $rendered[0];
        $this->assertSame('welcome', $template);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('testing', $data['env']);
        $this->assertTrue($data['debug']);
    }
}
