<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ApiController;
use App\Service\LicenceQueue;
use App\Service\LicenceRegister;
use App\Service\MasterWorkbook;
use App\Utils\Config;
use flight\Engine;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use Tests\Support\QueueDatabase;

class ApiControllerTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/licence_api_' . uniqid();
        mkdir($this->dir . '/incoming', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/incoming/*') ?: [], glob($this->dir . '/*') ?: []) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->dir . '/{incoming/.agent.lock,.*.lock}', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/incoming');
        if (is_dir($this->dir . '/processed')) {
            array_map('unlink', glob($this->dir . '/processed/*') ?: []);
            rmdir($this->dir . '/processed');
        }
        rmdir($this->dir);
    }

    public function testJsonUploadStoresValidFilesAndReportsRejected(): void
    {
        $response = $this->call('upload', $this->queueConfig(), ['files' => [
            ['name' => 'licence one.pdf', 'content' => base64_encode('%PDF-1.7 one')],
            ['name' => 'scan.docx', 'contentBytes' => base64_encode("PK\x03\x04 docx")],
            ['name' => 'pa.pdf', 'content' => ['$content-type' => 'application/pdf', '$content' => base64_encode('%PDF-1.4')]],
            ['name' => 'notes.txt', 'content' => base64_encode('hello')],
            ['name' => 'broken.pdf', 'content' => '***not base64***'],
            ['content' => base64_encode('%PDF-1.7')],
        ]]);

        $this->assertSame(201, $response['status']);
        $this->assertSame(['licence one.pdf', 'scan.docx', 'pa.pdf'], $response['body']['uploaded']);
        $this->assertCount(3, $response['body']['errors']);
        $this->assertStringContainsString('only .docx and .pdf', $response['body']['errors'][0]);
        $this->assertStringContainsString('base64', $response['body']['errors'][1]);
        $this->assertStringContainsString('"name" is required', $response['body']['errors'][2]);
        $this->assertSame('%PDF-1.7 one', file_get_contents($this->dir . '/incoming/licence one.pdf'));
    }

    public function testSingleObjectJsonUpload(): void
    {
        $response = $this->call('upload', $this->queueConfig(), ['name' => 'a.pdf', 'content' => 'data:application/pdf;base64,' . base64_encode('%PDF-1.7')]);

        $this->assertSame(201, $response['status']);
        $this->assertSame(['a.pdf'], $response['body']['uploaded']);
    }

    public function testUploadWithoutFilesIsABadRequest(): void
    {
        $response = $this->call('upload', $this->queueConfig(), []);

        $this->assertSame(400, $response['status']);
        $this->assertSame('no_files', $response['body']['error']);
    }

    public function testUploadWhereEveryFileIsInvalid(): void
    {
        $response = $this->call('upload', $this->queueConfig(), ['files' => [['name' => 'x.exe', 'content' => base64_encode('MZ')]]]);

        $this->assertSame(422, $response['status']);
        $this->assertSame('invalid_files', $response['body']['error']);
    }

    public function testFullFlowUploadProcessPollDownload(): void
    {
        $config = $this->queueConfig();
        $this->call('upload', $config, ['files' => [
            ['name' => 'a.pdf', 'content' => base64_encode('%PDF-a')],
            ['name' => 'b.pdf', 'content' => base64_encode('%PDF-b')],
        ]]);

        // Uploaded but not started
        $status = $this->call('status', $config)['body'];
        $this->assertSame('waiting_to_start', $status['state']);
        $this->assertFalse($status['ready']);
        $this->assertSame(2, $status['counts']['uploaded']);

        $blocked = $this->call('download', $config);
        $this->assertSame(409, $blocked['status']);
        $this->assertSame('processing_not_started', $blocked['body']['error']);

        // Start processing
        $process = $this->call('process', $config);
        $this->assertSame(202, $process['status']);
        $this->assertSame('processing', $process['body']['status']);
        $this->assertSame(2, $process['body']['queued']);

        $status = $this->call('status', $config)['body'];
        $this->assertSame('processing', $status['state']);
        $this->assertSame(['queued', 'queued'], array_column($status['documents'], 'state'));

        $blocked = $this->call('download', $config);
        $this->assertSame(409, $blocked['status']);
        $this->assertSame('still_processing', $blocked['body']['error']);
        $this->assertSame('30', $blocked['headers']['Retry-After'] ?? null);

        // Worker finishes a.pdf; b.pdf fails for good
        $queue = (new LicenceRegister($config))->queue();
        $this->assertInstanceOf(LicenceQueue::class, $queue);
        $job = $queue->reserve();
        $this->assertNotNull($job);
        $queue->complete($job['id'], 1, 0);
        unlink($this->dir . '/incoming/a.pdf');
        MasterWorkbook::open($this->dir . '/Master.xlsx')->save();
        $job = $queue->reserve();
        $this->assertNotNull($job);
        $queue->fail($job['id'], 1, 'Unreadable scan', true);

        $status = $this->call('status', $config)['body'];
        $this->assertSame('idle', $status['state']);
        $this->assertTrue($status['ready']);
        $this->assertSame(['name' => 'b.pdf', 'state' => 'failed', 'attempts' => 1, 'error' => 'Unreadable scan'], array_slice($status['documents'][0], 0, 4));

        // Failed documents do not block the download
        $download = $this->call('download', $config);
        $this->assertSame(200, $download['status']);
        $this->assertSame('1', $download['headers']['X-Failed-Documents']);
        $this->assertStringStartsWith('attachment; filename="Master.xlsx"', $download['headers']['Content-Disposition']);
        $this->assertStringStartsWith("PK\x03\x04", $download['raw']);
    }

    public function testProcessWithNothingToDo(): void
    {
        $response = $this->call('process', $this->queueConfig());

        $this->assertSame(200, $response['status']);
        $this->assertSame('idle', $response['body']['status']);
    }

    public function testProcessNeedsGeminiKey(): void
    {
        $response = $this->call('process', $this->config());

        $this->assertSame(503, $response['status']);
        $this->assertSame('extraction_not_configured', $response['body']['error']);
    }

    public function testDownloadWithoutWorkbookIs404(): void
    {
        $response = $this->call('download', $this->queueConfig());

        $this->assertSame(404, $response['status']);
        $this->assertSame('workbook_not_found', $response['body']['error']);
    }

    public function testWithoutDatabaseUploadedDocumentsWaitForProcess(): void
    {
        $data = $this->config()->all();
        $data['gemini']['api_key'] = 'test-key';
        $data['licences']['queue'] = ['dispatch' => 'none'];
        $config = new Config($data);
        touch($this->dir . '/incoming/a.pdf');

        $this->assertSame('waiting_to_start', $this->call('status', $config)['body']['state']);
        $process = $this->call('process', $config);
        $this->assertSame(202, $process['status']);
        $this->assertStringContainsString('worker or scheduled run', $process['body']['message']);
    }

    public function testInlineDispatchRunsAfterTheResponseWithoutExec(): void
    {
        $data = $this->queueConfig()->all();
        $data['licences']['queue'] = ['dispatch' => 'inline'];
        $config = new Config($data);
        touch($this->dir . '/incoming/a.pdf');
        $deferred = [];

        $response = $this->call('process', $config, [], function (callable $callback) use (&$deferred): void {
            $deferred[] = $callback;
        });

        $this->assertSame(202, $response['status']);
        $this->assertSame('processing', $response['body']['status']);
        $this->assertStringContainsString('Processing started', $response['body']['message']);
        $this->assertCount(1, $deferred, 'one run scheduled for after the response');
        // Nothing ran during the request itself
        $this->assertSame(1, $this->call('status', $config)['body']['counts']['queued']);
    }

    public function testResetRequiresConfirmation(): void
    {
        touch($this->dir . '/incoming/a.pdf');

        $response = $this->call('reset', $this->queueConfig(), ['confirm' => 'yes']);

        $this->assertSame(400, $response['status']);
        $this->assertSame('confirmation_required', $response['body']['error']);
        $this->assertFileExists($this->dir . '/incoming/a.pdf');
    }

    public function testResetIsBlockedWhileADocumentIsProcessing(): void
    {
        $config = $this->queueConfig();
        touch($this->dir . '/incoming/a.pdf');
        $queue = (new LicenceRegister($config))->queue();
        $this->assertInstanceOf(LicenceQueue::class, $queue);
        $queue->enqueue('a.pdf');
        $queue->reserve();

        $response = $this->call('reset', $config, ['confirm' => 'RESET']);

        $this->assertSame(409, $response['status']);
        $this->assertSame('reset_blocked', $response['body']['error']);
        $this->assertFileExists($this->dir . '/incoming/a.pdf');
    }

    public function testResetClearsEverything(): void
    {
        $config = $this->queueConfig();
        mkdir($this->dir . '/processed');
        touch($this->dir . '/incoming/a.pdf');
        touch($this->dir . '/incoming/b.docx');
        touch($this->dir . '/incoming/~$b.docx');
        touch($this->dir . '/incoming/.agent.lock');
        touch($this->dir . '/processed/old.pdf');
        file_put_contents($this->dir . '/agent.log', "[2026-11-04 09:00:00] INFO old.pdf: 1 record(s) added\n");
        MasterWorkbook::open($this->dir . '/Master.xlsx')->save();
        $queue = (new LicenceRegister($config))->queue();
        $this->assertInstanceOf(LicenceQueue::class, $queue);
        $queue->enqueue('a.pdf');
        $failed = $queue->enqueue('b.docx');
        $this->assertNotNull($failed);
        $queue->fail($failed, 3, 'boom', true);

        $response = $this->call('reset', $config, ['confirm' => 'RESET']);

        $this->assertSame(200, $response['status']);
        $this->assertSame(
            ['jobs' => 2, 'documents_waiting' => 2, 'documents_processed' => 1, 'workbook' => true, 'log' => true],
            $response['body']['deleted']
        );
        $this->assertSame(['.agent.lock'], array_values(array_diff(scandir($this->dir . '/incoming') ?: [], ['.', '..'])));
        $this->assertSame([], glob($this->dir . '/processed/*'));
        $this->assertFileDoesNotExist($this->dir . '/Master.xlsx');
        $this->assertFileDoesNotExist($this->dir . '/agent.log');
        $this->assertSame(['queued' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0], $queue->counts());

        $status = $this->call('status', $config)['body'];
        $this->assertSame('idle', $status['state']);
        $this->assertFalse($status['workbook']['exists']);
    }

    /**
     * Invoke one controller action against a fresh Engine.
     *
     * @param array<mixed> $json Request body (sent as application/json)
     * @param (\Closure(callable): void)|null $afterResponse
     * @return array{status:int, body:array<string,mixed>, headers:array<string,string>, raw:string}
     */
    private function call(string $action, Config $config, array $json = [], ?\Closure $afterResponse = null): array
    {
        $app = new Engine();
        $app->request()->type = 'application/json';
        $app->request()->data = new Collection($json);

        $captured = null;
        $app->map('json', function (array $data, int $code = 200) use (&$captured): void {
            $captured = [$data, $code];
        });

        (new ApiController($app, new LicenceRegister($config, $afterResponse)))->{$action}();

        $headers = [];
        foreach ($app->response()->getHeaders() as $name => $value) {
            $headers[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        // status() without an argument is the getter (returns the code, not the Response)
        $status = $app->response()->status();

        return [
            'status' => $captured !== null ? $captured[1] : (is_int($status) ? $status : 0),
            'body' => $captured !== null ? $captured[0] : [],
            'headers' => $headers,
            'raw' => $app->response()->getBody(),
        ];
    }

    private function queueConfig(): Config
    {
        QueueDatabase::create('sqlite:' . $this->dir . '/queue.sqlite');

        $data = $this->config()->all();
        $data['gemini']['api_key'] = 'test-key';
        $data['database'] = ['driver' => 'sqlite', 'file_path' => $this->dir . '/queue.sqlite'];
        $data['licences']['queue'] = ['dispatch' => 'none'];

        return new Config($data);
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
}
