<?php

declare(strict_types=1);

namespace App\Service;

use Throwable;

/**
 * Runs queued extraction jobs through the agent.
 *
 * Callers hold the agent's processor lock while draining, so only one worker
 * talks to Gemini at a time (keeps within free-tier rate limits).
 */
final class LicenceWorker
{
    /** One-shot runs wait for retries that become due within this many seconds before exiting. */
    public const MAX_RETRY_WAIT = 300;

    /** @var LicenceAgent */
    private $agent;

    /** @var LicenceQueue */
    private $queue;

    public function __construct(LicenceAgent $agent, LicenceQueue $queue)
    {
        $this->agent = $agent;
        $this->queue = $queue;
    }

    /**
     * Queue any new documents in the incoming folder.
     */
    public function sync(): int
    {
        $this->queue->releaseStale();

        return $this->queue->sync(array_map('basename', $this->agent->incomingFiles()));
    }

    /**
     * Process due jobs until none are left (or $shouldStop says so).
     *
     * @param (callable(): bool)|null $shouldStop Checked between jobs (e.g. on SIGTERM)
     * @return array{processed:int, failed:int, retrying:int, added:int, duplicates:int}
     */
    public function drain(?callable $shouldStop = null): array
    {
        $summary = ['processed' => 0, 'failed' => 0, 'retrying' => 0, 'added' => 0, 'duplicates' => 0];

        $this->sync();
        while (($shouldStop === null || !$shouldStop()) && ($job = $this->queue->reserve()) !== null) {
            $outcome = $this->work($job);
            $summary[$outcome['status']]++;
            $summary['added'] += $outcome['added'];
            $summary['duplicates'] += $outcome['duplicates'];
        }

        return $summary;
    }

    /**
     * Drain the queue, waiting for retry back-offs that fall due within
     * $maxRetryWait seconds instead of leaving them for the next run.
     * The caller must hold the agent's processor lock.
     *
     * @param (callable(int): void)|null $onWait Called with the seconds about to be slept
     * @param (callable(int): void)|null $sleep  Override for sleep() (tests)
     * @return array{processed:int, failed:int, retrying:int, added:int, duplicates:int}
     */
    public function drainUntilIdle(int $maxRetryWait, ?callable $onWait = null, ?callable $sleep = null): array
    {
        $totals = ['processed' => 0, 'failed' => 0, 'retrying' => 0, 'added' => 0, 'duplicates' => 0];
        $sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };

        do {
            foreach ($this->drain() as $key => $value) {
                $totals[$key] += $value;
            }
            $dueIn = $this->queue->nextDueIn();
            if ($dueIn !== null && $dueIn > 0 && $dueIn <= $maxRetryWait) {
                if ($onWait !== null) {
                    $onWait($dueIn);
                }
                $sleep($dueIn);
            }
        } while ($dueIn !== null && $dueIn <= $maxRetryWait);

        return $totals;
    }

    /**
     * @param array{id:int,file_name:string,attempts:int} $job
     * @return array{status:'processed'|'failed'|'retrying', added:int, duplicates:int}
     */
    private function work(array $job): array
    {
        $path = $this->agent->incomingDir() . DIRECTORY_SEPARATOR . $job['file_name'];
        $missing = !is_file($path);

        try {
            $result = $this->agent->processFile($path);
            $this->queue->complete($job['id'], $result['added'], $result['duplicates']);

            return ['status' => 'processed', 'added' => $result['added'], 'duplicates' => $result['duplicates']];
        } catch (Throwable $e) {
            // A deleted document can never succeed; anything else gets retried with back-off
            $retrying = $this->queue->fail($job['id'], $job['attempts'], $e->getMessage(), $missing);
            $this->agent->log(
                $retrying ? 'WARN' : 'ERROR',
                $job['file_name'] . ': ' . $e->getMessage()
                    . ($retrying ? sprintf(' (attempt %d of %d, will retry)', $job['attempts'], $this->queue->maxAttempts()) : '')
            );

            return ['status' => $retrying ? 'retrying' : 'failed', 'added' => 0, 'duplicates' => 0];
        }
    }
}
