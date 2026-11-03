<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\Config;
use RuntimeException;

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

    /**
     * Start over: delete every job, waiting and processed document, the workbook
     * and the activity log. Jobs go first so no worker picks up a document that
     * is about to disappear. Callers must not reset while a document is processing.
     *
     * @return array{jobs:int,documents_waiting:int,documents_processed:int,workbook:bool,log:bool}
     * @throws RuntimeException When a file cannot be deleted (e.g. open in Excel on Windows)
     */
    public function reset(): array
    {
        $queue = $this->queue();

        return [
            'jobs' => $queue !== null ? $queue->clear() : 0,
            'documents_waiting' => $this->deleteDocuments($this->incomingDir()),
            'documents_processed' => $this->deleteDocuments((string) $this->config->get('licences.processed_dir', '')),
            'workbook' => $this->deleteFile($this->masterPath()),
            'log' => $this->deleteFile((string) $this->config->get('licences.log_file', '')),
        ];
    }

    /**
     * Delete the .docx / .pdf documents (and Word "~$" lock files) in a folder.
     * Dotfiles such as the processor lock are left alone.
     */
    private function deleteDocuments(string $dir): int
    {
        $dir = rtrim($dir, '/\\');
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }

        $deleted = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            $isDocument = in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), LicenceAgent::EXTENSIONS, true);
            if ($entry[0] === '.' || !is_file($path) || !$isDocument) {
                continue;
            }
            $this->deleteFile($path);
            if (strpos($entry, '~$') !== 0) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return bool Whether a file was there and has been deleted
     */
    private function deleteFile(string $path): bool
    {
        if ($path === '' || !is_file($path)) {
            return false;
        }
        if (!@unlink($path)) {
            throw new RuntimeException(basename($path) . ' could not be deleted. Is it open in another program?');
        }

        return true;
    }
}
