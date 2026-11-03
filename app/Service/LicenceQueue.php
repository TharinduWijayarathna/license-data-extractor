<?php

declare(strict_types=1);

namespace App\Service;

use flight\database\SimplePdo;

/**
 * Database-backed job queue: one job per document in the incoming folder.
 *
 * Lifecycle: queued → processing → done, or back to queued with a back-off delay
 * after a failure, until max_attempts is reached and the job is marked failed.
 * The document itself stays in the incoming folder until it is processed, so
 * the folder remains the source of truth and nothing is lost if the DB is reset.
 */
final class LicenceQueue
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** Statuses that mean "this document still has a job in flight or awaiting retry". */
    private const ACTIVE = [self::QUEUED, self::PROCESSING, self::FAILED];

    /** @var SimplePdo */
    private $db;

    /** @var int */
    private $maxAttempts;

    /** @var int Base back-off in seconds; doubles each attempt */
    private $retryAfter;

    /** @var int Seconds after which a "processing" job is considered abandoned */
    private $staleAfter;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Unix time source (injectable for tests)
     */
    public function __construct(SimplePdo $db, int $maxAttempts = 3, int $retryAfter = 60, int $staleAfter = 900, ?callable $clock = null)
    {
        $this->db = $db;
        $this->maxAttempts = max(1, $maxAttempts);
        $this->retryAfter = max(0, $retryAfter);
        $this->staleAfter = max(60, $staleAfter);
        $this->clock = $clock ?? 'time';
    }

    /**
     * Queue a document unless it already has an active job.
     *
     * @return int|null New job id, or null when already queued
     */
    public function enqueue(string $fileName): ?int
    {
        if ($this->activeJob($fileName) !== null) {
            return null;
        }

        $now = $this->now();
        $id = $this->db->insert('licence_jobs', [
            'file_name' => $fileName,
            'status' => self::QUEUED,
            'attempts' => 0,
            'available_at' => $now,
            'records_added' => 0,
            'duplicates' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $id;
    }

    /**
     * Queue every given document that has no active job yet (e.g. files copied
     * straight into the incoming folder).
     *
     * @param array<int,string> $fileNames
     * @return int Number of new jobs
     */
    public function sync(array $fileNames): int
    {
        $created = 0;
        foreach ($fileNames as $fileName) {
            if ($this->enqueue($fileName) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Claim the next due job for processing.
     *
     * @phpstan-impure
     * @return array{id:int,file_name:string,attempts:int}|null
     */
    public function reserve(): ?array
    {
        $now = $this->now();

        // The conditional UPDATE makes the claim atomic even with several workers
        for ($try = 0; $try < 5; $try++) {
            $row = $this->db->fetchRow(
                'SELECT id, file_name, attempts FROM licence_jobs
                 WHERE status = ? AND available_at <= ? ORDER BY available_at, id LIMIT 1',
                [self::QUEUED, $now]
            );
            if ($row === null) {
                return null;
            }

            $claimed = $this->db->update(
                'licence_jobs',
                ['status' => self::PROCESSING, 'reserved_at' => $now, 'attempts' => (int) $row['attempts'] + 1, 'updated_at' => $now],
                'id = ? AND status = ?',
                [(int) $row['id'], self::QUEUED]
            );
            if ($claimed === 1) {
                return [
                    'id' => (int) $row['id'],
                    'file_name' => (string) $row['file_name'],
                    'attempts' => (int) $row['attempts'] + 1,
                ];
            }
        }

        return null;
    }

    public function complete(int $id, int $added, int $duplicates): void
    {
        $this->db->update('licence_jobs', [
            'status' => self::DONE,
            'records_added' => $added,
            'duplicates' => $duplicates,
            'error' => null,
            'updated_at' => $this->now(),
        ], 'id = ?', [$id]);
    }

    /**
     * Record a failure: re-queue with exponential back-off, or give up after max_attempts.
     *
     * @param bool $permanent Skip remaining retries (e.g. the document was deleted)
     * @return bool True when the job will be retried
     */
    public function fail(int $id, int $attempts, string $error, bool $permanent = false): bool
    {
        $now = $this->now();
        $retry = !$permanent && $attempts < $this->maxAttempts;

        $this->db->update('licence_jobs', [
            'status' => $retry ? self::QUEUED : self::FAILED,
            'available_at' => $retry ? $now + $this->retryAfter * (2 ** ($attempts - 1)) : $now,
            'error' => mb_substr($error, 0, 1000),
            'updated_at' => $now,
        ], 'id = ?', [$id]);

        return $retry;
    }

    /**
     * Manually retry a failed job (fresh attempt counter).
     */
    public function retry(int $id): bool
    {
        $now = $this->now();

        return $this->db->update('licence_jobs', [
            'status' => self::QUEUED,
            'attempts' => 0,
            'available_at' => $now,
            'error' => null,
            'updated_at' => $now,
        ], 'id = ? AND status = ?', [$id, self::FAILED]) === 1;
    }

    /**
     * Put jobs whose worker died mid-run back in the queue.
     *
     * @return int Jobs released
     */
    public function releaseStale(): int
    {
        $now = $this->now();

        return $this->db->update('licence_jobs', [
            'status' => self::QUEUED,
            'available_at' => $now,
            'updated_at' => $now,
        ], 'status = ? AND reserved_at < ?', [self::PROCESSING, $now - $this->staleAfter]);
    }

    /**
     * Seconds until the next queued job becomes due (0 = now), or null when nothing is queued.
     *
     * @phpstan-impure
     */
    public function nextDueIn(): ?int
    {
        $next = $this->db->fetchColumn(
            'SELECT MIN(available_at) FROM licence_jobs WHERE status = ?',
            [self::QUEUED]
        )[0] ?? null;

        return $next === null ? null : max(0, (int) $next - $this->now());
    }

    /**
     * Current (non-done) job for each document, keyed by file name.
     *
     * @return array<string,array{id:int,status:string,attempts:int,available_at:int,error:?string}>
     */
    public function activeJobs(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, file_name, status, attempts, available_at, error FROM licence_jobs
             WHERE status IN ({$placeholders}) ORDER BY id",
            self::ACTIVE
        );

        $jobs = [];
        foreach ($rows as $row) {
            $jobs[(string) $row['file_name']] = [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'attempts' => (int) $row['attempts'],
                'available_at' => (int) $row['available_at'],
                'error' => $row['error'] !== null ? (string) $row['error'] : null,
            ];
        }

        return $jobs;
    }

    /**
     * @return array{queued:int,processing:int,failed:int,done:int}
     */
    public function counts(): array
    {
        $counts = [self::QUEUED => 0, self::PROCESSING => 0, self::FAILED => 0, self::DONE => 0];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS total FROM licence_jobs GROUP BY status') as $row) {
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeJob(string $fileName): ?array
    {
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE), '?'));
        $row = $this->db->fetchRow(
            "SELECT id FROM licence_jobs WHERE file_name = ? AND status IN ({$placeholders}) LIMIT 1",
            array_merge([$fileName], self::ACTIVE)
        );

        return $row === null ? null : $row->getData();
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
