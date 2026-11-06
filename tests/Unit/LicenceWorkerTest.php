<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\LicenceAgent;
use App\Service\LicenceExtractor;
use App\Service\LicenceNormalizer;
use App\Service\LicenceQueue;
use App\Service\LicenceWorker;
use App\Service\MasterWorkbook;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\QueueDatabase;

class LicenceWorkerTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var int */
    private $now = 1_000_000;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/licence_worker_' . uniqid();
        mkdir($this->root . '/incoming', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testDrainProcessesQueuedAndDroppedDocuments(): void
    {
        touch($this->root . '/incoming/a.pdf');
        touch($this->root . '/incoming/b.pdf');
        [$agent, $queue] = $this->build(['a.pdf' => [self::raw('1')], 'b.pdf' => [self::raw('2'), self::raw('1')]]);
        $queue->enqueue('a.pdf'); // b.pdf was "dropped into the folder" — sync() picks it up

        $summary = (new LicenceWorker($agent, $queue))->drain();

        $this->assertSame(['processed' => 2, 'failed' => 0, 'retrying' => 0, 'added' => 2, 'duplicates' => 1], $summary);
        $this->assertSame(['queued' => 0, 'processing' => 0, 'failed' => 0, 'done' => 2], $queue->counts());
        $this->assertFileExists($this->root . '/processed/b.pdf');
        $this->assertCount(2, MasterWorkbook::open($this->root . '/Master.xlsx')->rows());
    }

    public function testFailingDocumentIsRetriedThenMarkedFailed(): void
    {
        touch($this->root . '/incoming/bad.pdf');
        [$agent, $queue] = $this->build(['bad.pdf' => new RuntimeException('HTTP 503')], 2);
        $worker = new LicenceWorker($agent, $queue);

        $this->assertSame(1, $worker->drain()['retrying']);
        $this->now += 60;
        $this->assertSame(1, $worker->drain()['failed']);

        $this->assertFileExists($this->root . '/incoming/bad.pdf', 'failed documents stay in the inbox');
        $this->assertSame('failed', $queue->activeJobs()['bad.pdf']['status']);
        $log = (string) file_get_contents($this->root . '/agent.log');
        $this->assertStringContainsString('WARN bad.pdf: HTTP 503 (attempt 1 of 2, will retry)', $log);
        $this->assertStringContainsString('ERROR bad.pdf: HTTP 503', $log);
    }

    public function testDrainUntilIdleWaitsForDueRetries(): void
    {
        touch($this->root . '/incoming/bad.pdf');
        [$agent, $queue] = $this->build(['bad.pdf' => new RuntimeException('HTTP 503')], 2);
        $waits = [];

        $summary = (new LicenceWorker($agent, $queue))->drainUntilIdle(
            300,
            function (int $seconds) use (&$waits): void {
                $waits[] = $seconds;
            },
            function (int $seconds): void {
                $this->now += $seconds;
            }
        );

        $this->assertSame([60], $waits, 'waited once for the back-off instead of exiting');
        $this->assertSame(1, $summary['retrying']);
        $this->assertSame(1, $summary['failed']);
    }

    public function testDrainUntilIdleLeavesDistantRetriesForLater(): void
    {
        touch($this->root . '/incoming/bad.pdf');
        [$agent, $queue] = $this->build(['bad.pdf' => new RuntimeException('HTTP 503')]);

        $summary = (new LicenceWorker($agent, $queue))->drainUntilIdle(30, null, function (): void {
            $this->fail('must not sleep for a retry beyond the limit');
        });

        $this->assertSame(1, $summary['retrying']);
        $this->assertSame('queued', $queue->activeJobs()['bad.pdf']['status']);
    }

    public function testDeletedDocumentFailsWithoutRetrying(): void
    {
        [$agent, $queue] = $this->build([]);
        $queue->enqueue('gone.pdf');

        $summary = (new LicenceWorker($agent, $queue))->drain();

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $queue->activeJobs()['gone.pdf']['attempts']);
    }

    public function testProcessorLockIsVisibleToOtherProcesses(): void
    {
        [$agent] = $this->build([]);

        $this->assertFalse(LicenceAgent::isProcessingIn($this->root . '/incoming'));
        $this->assertTrue($agent->tryLock());
        $this->assertTrue(LicenceAgent::isProcessingIn($this->root . '/incoming'));
        $agent->unlock();
        $this->assertFalse(LicenceAgent::isProcessingIn($this->root . '/incoming'));
    }

    /**
     * @param array<string,array<int,array<string,mixed>>|RuntimeException> $byFile
     * @return array{0:LicenceAgent,1:LicenceQueue}
     */
    private function build(array $byFile, int $maxAttempts = 3): array
    {
        $extractor = new class ($byFile) implements LicenceExtractor {
            /** @var array<string,array<int,array<string,mixed>>|RuntimeException> */
            private $byFile;

            /**
             * @param array<string,array<int,array<string,mixed>>|RuntimeException> $byFile
             */
            public function __construct(array $byFile)
            {
                $this->byFile = $byFile;
            }

            public function extract(string $path): array
            {
                $result = $this->byFile[basename($path)] ?? [];
                if ($result instanceof RuntimeException) {
                    throw $result;
                }

                return $result;
            }
        };

        $agent = new LicenceAgent(
            $extractor,
            new LicenceNormalizer(),
            $this->root . '/incoming',
            $this->root . '/processed',
            $this->root . '/Master.xlsx',
            $this->root . '/agent.log'
        );
        $queue = new LicenceQueue(QueueDatabase::create(), $maxAttempts, 60, 900, function (): int {
            return $this->now;
        });

        return [$agent, $queue];
    }

    /**
     * @return array<string,mixed>
     */
    private static function raw(string $n): array
    {
        return [
            'VLFileNumber' => "LCS/VEN/{$n}/2026",
            'VLNumber' => "000{$n}/26/N",
            'CompanyName' => 'TEST',
            'Addresses' => ['NO. 1, MAIN STREET, MATARA'],
            'EffectiveDate' => '02/10/2026',
            'Wired' => true,
            'Wireless' => false,
            'Exclusions' => [],
            'Districts' => ['MATARA'],
        ];
    }
}
