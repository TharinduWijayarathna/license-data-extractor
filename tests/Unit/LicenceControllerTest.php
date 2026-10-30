<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\LicenceController;
use App\Utils\Config;
use flight\Engine;
use flight\Session;
use PHPUnit\Framework\TestCase;

class LicenceControllerTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/licence_ctrl_' . uniqid();
        mkdir($this->dir . '/incoming', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/incoming/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/incoming');
        @unlink($this->dir . '/agent.log');
        rmdir($this->dir);
    }

    public function testIndexRendersQueueAndConsumesFlash(): void
    {
        touch($this->dir . '/incoming/a.pdf');
        file_put_contents(
            $this->dir . '/agent.log',
            "[2026-10-31 09:00:00] INFO a.pdf: 1 record(s) added\n[2026-10-31 09:05:00] ERROR b.pdf: Gemini API request failed (HTTP 429)\n"
        );
        $session = $this->session();
        $session->set('licences_flash', ['type' => 'ok', 'messages' => ['Done']]);

        $calls = [];
        $app = $this->engine($calls);

        (new LicenceController($app, $this->config(), $session))->index();

        $this->assertCount(1, $calls['render'] ?? []);
        [$template, $data] = $calls['render'][0];
        $this->assertSame('licences/index', $template);
        $this->assertFalse($data['exists']);
        $this->assertSame([], $data['rows']);
        $this->assertSame(['a.pdf'], array_column($data['pending'], 'name'));
        $this->assertFalse($data['has_api_key']);
        $this->assertSame(20, $data['max_upload_mb']);
        $this->assertSame(['type' => 'ok', 'messages' => ['Done']], $data['flash']);
        $this->assertSame(0, $data['expiring_count']);
        $this->assertSame(
            ['time' => '2026-10-31 09:05:00', 'level' => 'error', 'subject' => 'b.pdf', 'detail' => 'Gemini API request failed (HTTP 429)'],
            $data['activity'][0]
        );
        $this->assertSame('1 record(s) added', $data['activity'][1]['detail']);

        $this->assertNull($session->get('licences_flash'));
    }

    public function testProcessWithoutApiKeyRedirectsWithError(): void
    {
        $session = $this->session();
        $calls = [];
        $app = $this->engine($calls);

        (new LicenceController($app, $this->config(), $session))->process();

        $this->assertSame([['/']], $calls['redirect'] ?? []);

        $flash = $session->get('licences_flash');
        $this->assertIsArray($flash);
        $this->assertSame('error', $flash['type']);
        $this->assertStringContainsString('GEMINI_API_KEY', $flash['messages'][0]);
    }

    private function config(): Config
    {
        return new Config([
            'app' => ['base_url' => '/'],
            'gemini' => ['api_key' => ''],
            'licences' => [
                'incoming_dir' => $this->dir . '/incoming',
                'processed_dir' => $this->dir . '/processed',
                'master_xlsx' => $this->dir . '/Master.xlsx',
                'log_file' => $this->dir . '/agent.log',
                'max_upload_mb' => 20,
            ],
        ]);
    }

    private function session(): Session
    {
        return new Session(['test_mode' => true, 'auto_commit' => false]);
    }

    /**
     * Real Engine with render/redirect captured instead of executed.
     *
     * @param array<string,array<int,array<int,mixed>>> $calls
     * @return Engine
     */
    private function engine(array &$calls): Engine
    {
        $app = new Engine();
        foreach (['render', 'redirect'] as $method) {
            $app->map($method, function (...$args) use (&$calls, $method): void {
                $calls[$method][] = $args;
            });
        }

        return $app;
    }
}
