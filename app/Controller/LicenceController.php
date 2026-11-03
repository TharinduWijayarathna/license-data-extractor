<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LicenceAgentFactory;
use App\Service\LicenceNormalizer;
use App\Service\LicenceQueue;
use App\Service\LicenceRecord;
use App\Service\LicenceRegister;
use App\Service\MasterWorkbook;
use App\Utils\Config;
use flight\Engine;
use flight\net\UploadedFile;
use flight\Session;

/**
 * Licence dashboard: upload documents, run the extraction agent, browse and
 * download the master workbook. Forms use post/redirect/get with flash messages.
 */
class LicenceController
{
    private const FLASH_KEY = 'licences_flash';

    private const LOG_LINES = 20;

    /** Records expiring within this many days are flagged "expiring soon". */
    private const EXPIRY_WARNING_DAYS = 90;

    /** @var Engine */
    private $app;

    /** @var Config */
    private $config;

    /** @var Session */
    private $session;

    /** @var LicenceRegister */
    private $register;

    /**
     * @param Engine $app
     */
    public function __construct(Engine $app, Config $config, Session $session, LicenceRegister $register)
    {
        $this->app = $app;
        $this->config = $config;
        $this->session = $session;
        $this->register = $register;
    }

    public function index(): void
    {
        $path = $this->register->masterPath();
        $exists = is_file($path);

        $flash = $this->session->get(self::FLASH_KEY);
        $this->session->delete(self::FLASH_KEY);

        $rows = $this->withExpiryStatus($exists ? MasterWorkbook::open($path)->rows() : []);
        $queue = $this->register->queue();

        $this->app->render('licences/index', [
            'columns' => LicenceRecord::COLUMNS,
            'rows' => $rows,
            'expiring_count' => count(array_filter($rows, function (array $row): bool {
                return in_array($row['status'], ['expired', 'soon'], true);
            })),
            'expiry_warning_days' => self::EXPIRY_WARNING_DAYS,
            'model' => (string) $this->config->get('gemini.model', ''),
            'exists' => $exists,
            'updated_at' => $exists ? (int) filemtime($path) : null,
            'pending' => $this->register->documents(),
            'queue_enabled' => $queue !== null,
            'processing' => $this->register->isProcessing(),
            'max_attempts' => $queue !== null ? $queue->maxAttempts() : 0,
            'now' => time(),
            'max_upload_mb' => intdiv($this->register->inbox()->maxBytes(), 1048576),
            'has_api_key' => $this->register->hasGeminiKey(),
            'activity' => $this->recentLog(),
            'flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function upload(): void
    {
        $inbox = $this->register->inbox();
        $stored = [];
        $errors = [];

        foreach ($this->uploadedFiles('documents') as $file) {
            try {
                $stored[] = $inbox->store(
                    $file->getClientFilename(),
                    $file->getTempName(),
                    $file->getError(),
                    $file->getSize()
                );
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($stored === [] && $errors === []) {
            $errors[] = 'Choose at least one .docx or .pdf file to upload.';
        }

        $messages = [];
        if ($stored !== []) {
            $queue = $this->register->queue();
            if ($queue !== null && $this->register->hasGeminiKey()) {
                $queue->sync($stored);
                $messages[] = count($stored) . ' document(s) queued. ' . $this->dispatch();
            } else {
                $messages[] = count($stored) . ' document(s) added to the inbox.';
            }
        }
        $this->redirectWithFlash($errors === [] ? 'ok' : 'error', array_merge($messages, $errors));
    }

    public function process(): void
    {
        if (!$this->register->hasGeminiKey()) {
            $this->redirectWithFlash('error', ['GEMINI_API_KEY is not set. Add it to .env and reload.']);
            return;
        }

        // Queue mode: hand the inbox to the background processor and return at once
        if ($this->register->queue() !== null) {
            $queued = $this->register->queueWaiting();
            $this->redirectWithFlash('ok', [
                ($queued > 0 ? "{$queued} document(s) queued. " : '') . $this->dispatch(),
            ]);
            return;
        }

        // No database: process synchronously within this request

        // One Gemini call per document; allow a long batch to finish
        set_time_limit(0);

        try {
            $summary = LicenceAgentFactory::fromConfig($this->config)->run();
        } catch (\Throwable $e) {
            $this->redirectWithFlash('error', ['Processing failed: ' . $e->getMessage()]);
            return;
        }

        if ($summary['locked']) {
            $this->redirectWithFlash('error', ['Another run is already in progress. Try again in a minute.']);
            return;
        }
        if ($summary['files'] === 0) {
            $this->redirectWithFlash('ok', ['Nothing to process — the queue is empty.']);
            return;
        }

        $messages = [sprintf(
            'Processed %d of %d file(s): %d record(s) added, %d duplicate(s) skipped.',
            $summary['processed'],
            $summary['files'],
            $summary['added'],
            $summary['duplicates']
        )];
        if ($summary['failed'] > 0) {
            $messages[] = $summary['failed'] . ' file(s) failed and stay in the queue — see the activity log below.';
        }

        $this->redirectWithFlash($summary['failed'] > 0 ? 'error' : 'ok', $messages);
    }

    public function retry(string $id): void
    {
        $queue = $this->register->queue();
        if ($queue === null || !$queue->retry((int) $id)) {
            $this->redirectWithFlash('error', ['That job could not be retried — it may already be running.']);
            return;
        }

        $this->redirectWithFlash('ok', ['Queued for another attempt. ' . $this->dispatch()]);
    }

    /**
     * Lightweight JSON for the dashboard to poll while jobs are in flight.
     */
    public function status(): void
    {
        $queue = $this->register->queue();
        $jobs = $queue !== null ? $queue->activeJobs() : [];
        $master = $this->register->masterPath();

        $inFlight = count(array_filter($jobs, function (array $job): bool {
            return $job['status'] !== LicenceQueue::FAILED;
        }));

        $this->app->json([
            'in_flight' => $inFlight,
            'processing' => $this->register->isProcessing(),
            // Changes whenever a job moves on or the workbook is written
            'version' => md5((string) json_encode([$jobs, is_file($master) ? filemtime($master) : 0])),
        ]);
    }

    public function download(): void
    {
        $path = $this->register->masterPath();
        if (!is_file($path)) {
            $this->app->halt(404, 'The master workbook has not been created yet.');
            return;
        }

        $this->app->response()
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="' . basename($path) . '"')
            ->write((string) file_get_contents($path));
    }

    /**
     * @param array<int,string> $messages
     */
    private function redirectWithFlash(string $type, array $messages): void
    {
        $this->session->set(self::FLASH_KEY, ['type' => $type, 'messages' => $messages]);
        $this->app->redirect($this->config->baseUrl());
    }

    /**
     * Start background processing per licences.queue.dispatch; returns a status sentence.
     */
    private function dispatch(): string
    {
        switch ($this->register->startProcessor()) {
            case LicenceRegister::RUN_ALREADY_RUNNING:
                return 'The worker will pick them up shortly.';
            case LicenceRegister::RUN_DEFERRED:
                return 'They will be processed by the worker or the next scheduled run.';
            case LicenceRegister::RUN_STARTED:
                return 'Extracting in the background — this page updates automatically.';
            default:
                return 'Could not start a background run; start `php runway licences:work` or wait for the schedule.';
        }
    }

    /**
     * Tag each row "expired", "soon" (within EXPIRY_WARNING_DAYS) or "valid".
     *
     * @param array<int,array<string,string>> $rows
     * @return array<int,array<string,string>>
     */
    private function withExpiryStatus(array $rows): array
    {
        $today = new \DateTimeImmutable('today');
        $warnFrom = $today->modify('+' . self::EXPIRY_WARNING_DAYS . ' days');

        foreach ($rows as $i => $row) {
            $expiry = LicenceNormalizer::parseDate($row['ExpireDate']);
            if ($expiry === null) {
                $status = 'unknown';
            } elseif ($expiry < $today) {
                $status = 'expired';
            } elseif ($expiry <= $warnFrom) {
                $status = 'soon';
            } else {
                $status = 'valid';
            }
            $rows[$i]['status'] = $status;
        }

        return $rows;
    }

    /**
     * @return array<int,UploadedFile>
     */
    private function uploadedFiles(string $field): array
    {
        $files = $this->app->request()->getUploadedFiles()[$field] ?? [];

        return is_array($files) ? $files : [$files];
    }

    /**
     * Last entries of the agent log, newest first. Agent messages look like
     * "<file name>: <detail>", so they are split into subject + detail for display.
     *
     * @return array<int,array{time:string,level:string,subject:string,detail:string}>
     */
    private function recentLog(): array
    {
        $logFile = (string) $this->config->get('licences.log_file', '');
        if ($logFile === '' || !is_file($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $entries = [];
        foreach (array_reverse(array_slice($lines, -self::LOG_LINES)) as $line) {
            $time = '';
            $level = 'info';
            $message = $line;
            if (preg_match('/^\[([^\]]+)\] (INFO|WARN|ERROR) (.*)$/', $line, $m)) {
                [, $time, $level, $message] = $m;
                $level = strtolower($level);
            }

            // Stored file names never contain ":" (see LicenceInbox::safeName), so the first ": " is the separator
            $parts = explode(': ', $message, 2);
            $entries[] = [
                'time' => $time,
                'level' => $level,
                'subject' => count($parts) === 2 ? $parts[0] : $message,
                'detail' => count($parts) === 2 ? $parts[1] : '',
            ];
        }

        return $entries;
    }
}
