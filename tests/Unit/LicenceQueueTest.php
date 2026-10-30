<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\LicenceQueue;
use PHPUnit\Framework\TestCase;
use Tests\Support\QueueDatabase;

class LicenceQueueTest extends TestCase
{
    /** @var int */
    private $now = 1_000_000;

    private function queue(int $maxAttempts = 3): LicenceQueue
    {
        return new LicenceQueue(QueueDatabase::create(), $maxAttempts, 60, 900, function (): int {
            return $this->now;
        });
    }

    public function testEnqueueSkipsDocumentsWithAnActiveJob(): void
    {
        $queue = $this->queue();

        $this->assertNotNull($queue->enqueue('a.pdf'));
        $this->assertNull($queue->enqueue('a.pdf'));
        $this->assertSame(1, $queue->sync(['a.pdf', 'b.docx']));
        $this->assertSame(['queued' => 2, 'processing' => 0, 'failed' => 0, 'done' => 0], $queue->counts());
    }

    public function testReserveClaimsJobsInOrderOnce(): void
    {
        $queue = $this->queue();
        $queue->sync(['a.pdf', 'b.pdf']);

        $first = $queue->reserve();
        $second = $queue->reserve();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('a.pdf', $first['file_name']);
        $this->assertSame(1, $first['attempts']);
        $this->assertSame('b.pdf', $second['file_name']);
        $this->assertNull($queue->reserve());
        $this->assertSame(2, $queue->counts()['processing']);
    }

    public function testFailureBacksOffThenGivesUp(): void
    {
        $queue = $this->queue(2);
        $queue->enqueue('a.pdf');

        $job = $queue->reserve();
        $this->assertNotNull($job);
        $this->assertTrue($queue->fail($job['id'], $job['attempts'], 'HTTP 429'));
        $this->assertNull($queue->reserve(), 'not due until the back-off has passed');
        $this->assertSame(60, $queue->nextDueIn());

        $this->now += 60;
        $job = $queue->reserve();
        $this->assertNotNull($job);
        $this->assertSame(2, $job['attempts']);
        $this->assertFalse($queue->fail($job['id'], $job['attempts'], 'HTTP 429'));

        $active = $queue->activeJobs();
        $this->assertSame('failed', $active['a.pdf']['status']);
        $this->assertSame('HTTP 429', $active['a.pdf']['error']);
        $this->assertNull($queue->enqueue('a.pdf'), 'a failed document is not re-queued automatically');
        $this->assertNull($queue->nextDueIn());
    }

    public function testManualRetryResetsAttempts(): void
    {
        $queue = $this->queue(1);
        $id = (int) $queue->enqueue('a.pdf');
        $job = $queue->reserve();
        $this->assertNotNull($job);
        $queue->fail($job['id'], $job['attempts'], 'boom');

        $this->assertTrue($queue->retry($id));
        $this->assertFalse($queue->retry($id), 'only failed jobs can be retried');
        $job = $queue->reserve();
        $this->assertNotNull($job);
        $this->assertSame(1, $job['attempts']);
    }

    public function testCompletedDocumentCanBeQueuedAgainLater(): void
    {
        $queue = $this->queue();
        $queue->enqueue('a.pdf');
        $job = $queue->reserve();
        $this->assertNotNull($job);
        $queue->complete($job['id'], 1, 0);

        $this->assertSame([], $queue->activeJobs());
        $this->assertNotNull($queue->enqueue('a.pdf'));
    }

    public function testStaleProcessingJobsAreReleased(): void
    {
        $queue = $this->queue();
        $queue->enqueue('a.pdf');
        $this->assertNotNull($queue->reserve());

        $this->assertSame(0, $queue->releaseStale());
        $this->now += 901;
        $this->assertSame(1, $queue->releaseStale());
        $this->assertNotNull($queue->reserve());
    }
}
