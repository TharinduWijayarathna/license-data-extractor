<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\Config;

/**
 * Operations shared by the dashboard and the REST API: the inbox, the job
 * queue, the state of every waiting document and starting a processor run.
 */
class LicenceRegister
{
    /** Document is in the inbox but nobody asked to process it yet. */
    public const UPLOADED = 'uploaded';

    /** startProcessor() outcomes */
    public const RUN_ALREADY_RUNNING = 'already_running';
    public const RUN_STARTED = 'started';
    public const RUN_DEFERRED = 'deferred';
    public const RUN_FAILED = 'failed';

    /** @var Config */
    private $config;

    /** @var LicenceQueue|null */
    private $queue = null;

    /** @var bool */
    private $queueResolved = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function inbox(): LicenceInbox
    {
        return new LicenceInbox(
            $this->incomingDir(),
            (int) $this->config->get('licences.max_upload_mb', 20) * 1048576
        );
    }

    /**
     * The job queue, or null when the database is disabled / not migrated.
     */
    public function queue(): ?LicenceQueue
    {
        if (!$this->queueResolved) {
            $this->queue = LicenceAgentFactory::queue($this->config);
            $this->queueResolved = true;
        }

        return $this->queue;
    }

    public function incomingDir(): string
    {
        return (string) $this->config->get('licences.incoming_dir', '');
    }

    public function masterPath(): string
    {
        return (string) $this->config->get('licences.master_xlsx', '');
    }

    public function hasGeminiKey(): bool
    {
        return LicenceAgentFactory::hasApiKey($this->config);
    }

    /**
     * Whether a processor currently holds the lock (a run or the long-running worker).
     */
    public function isProcessing(): bool
    {
        return LicenceAgent::isProcessingIn($this->incomingDir());
    }

    /**
     * Documents waiting in the inbox (oldest first), each with its current job
     * and a single state: uploaded, queued, processing or failed.
     *
     * @return array<int,array{name:string,size:int,modified:int,job:array{id:int,status:string,attempts:int,available_at:int,error:?string}|null,state:string}>
     */
    public function documents(): array
    {
        $queue = $this->queue();
        $jobs = $queue !== null ? $queue->activeJobs() : [];
        // Without the queue, a held lock means the batch run is working through the inbox
        $batchRunning = $queue === null && $this->isProcessing();

        $documents = [];
        foreach ($this->inbox()->pending() as $file) {
            $job = $jobs[$file['name']] ?? null;
            if ($job !== null) {
                $state = $job['status'];
            } else {
                $state = $batchRunning ? LicenceQueue::PROCESSING : self::UPLOADED;
            }
            $documents[] = $file + ['job' => $job, 'state' => $state];
        }

        return $documents;
    }

    /**
     * Number of documents in each state.
     *
     * @param array<int,array{state:string}> $documents From documents()
     * @return array{uploaded:int,queued:int,processing:int,failed:int}
     */
    public static function countStates(array $documents): array
    {
        $counts = [self::UPLOADED => 0, LicenceQueue::QUEUED => 0, LicenceQueue::PROCESSING => 0, LicenceQueue::FAILED => 0];
        foreach ($documents as $document) {
            if (isset($counts[$document['state']])) {
                $counts[$document['state']]++;
            }
        }

        return $counts;
    }

    /**
     * Create a job for every inbox document that does not have one yet.
     *
     * @return int New jobs (always 0 without the queue)
     */
    public function queueWaiting(): int
    {
        $queue = $this->queue();

        return $queue === null ? 0 : $queue->sync(array_column($this->inbox()->pending(), 'name'));
    }

    /**
     * Make sure something will process the inbox, per licences.queue.dispatch.
     *
     * @return string One of the RUN_* constants
     */
    public function startProcessor(): string
    {
        if ($this->isProcessing()) {
            return self::RUN_ALREADY_RUNNING;
        }
        if ((string) $this->config->get('licences.queue.dispatch', 'background') !== 'background') {
            return self::RUN_DEFERRED;
        }

        return LicenceAgentFactory::dispatcher($this->config)->dispatch() ? self::RUN_STARTED : self::RUN_FAILED;
    }

}
