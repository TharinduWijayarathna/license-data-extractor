<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Throwable;

/**
 * The unattended daily job: read every licence document in the incoming
 * folder, append new records to the master workbook, archive the document.
 *
 * Files that fail stay in the incoming folder and are retried on the next run;
 * every problem is written to the log file instead of stopping the run.
 */
final class LicenceAgent
{
    public const EXTENSIONS = ['docx', 'pdf'];

    /** @var LicenceExtractor */
    private $extractor;

    /** @var LicenceNormalizer */
    private $normalizer;

    /** @var string */
    private $incomingDir;

    /** @var string */
    private $processedDir;

    /** @var string */
    private $masterPath;

    /** @var string */
    private $logFile;

    /** @var resource|null Held while this instance owns the processor lock */
    private $lock = null;

    public function __construct(
        LicenceExtractor $extractor,
        LicenceNormalizer $normalizer,
        string $incomingDir,
        string $processedDir,
        string $masterPath,
        string $logFile
    ) {
        $this->extractor = $extractor;
        $this->normalizer = $normalizer;
        $this->incomingDir = rtrim($incomingDir, '/\\');
        $this->processedDir = rtrim($processedDir, '/\\');
        $this->masterPath = $masterPath;
        $this->logFile = $logFile;
    }

    /**
     * Process every document in the incoming folder (the unqueued batch mode).
     *
     * @return array{files:int, processed:int, failed:int, added:int, duplicates:int, locked:bool}
     */
    public function run(): array
    {
        $summary = ['files' => 0, 'processed' => 0, 'failed' => 0, 'added' => 0, 'duplicates' => 0, 'locked' => false];

        // Prevent two overlapping runs (e.g. scheduler + manual) processing the same files
        if (!$this->tryLock()) {
            $this->log('WARN', 'Another run is in progress; skipping.');
            $summary['locked'] = true;
            return $summary;
        }

        try {
            $files = $this->incomingFiles();
            $summary['files'] = count($files);

            foreach ($files as $file) {
                try {
                    $result = $this->processFile($file);
                    $summary['processed']++;
                    $summary['added'] += $result['added'];
                    $summary['duplicates'] += $result['duplicates'];
                } catch (Throwable $e) {
                    $summary['failed']++;
                    $this->log('ERROR', basename($file) . ': ' . $e->getMessage());
                }
            }
        } finally {
            $this->unlock();
        }

        return $summary;
    }

    /**
     * Extract one document, append its new records to the workbook and archive it.
     * Throws on any failure, leaving the document in the incoming folder.
     *
     * @return array{added:int, duplicates:int}
     */
    public function processFile(string $path): array
    {
        $name = basename($path);
        if (!is_file($path)) {
            throw new RuntimeException('Document is no longer in the incoming folder.');
        }

        // The slow part (the Gemini call) runs outside the workbook lock
        $records = [];
        foreach ($this->extractor->extract($path) as $raw) {
            $record = $this->normalizer->normalize($raw);
            foreach ($this->normalizer->warnings() as $warning) {
                $this->log('WARN', "{$name}: {$record->vlNumber}: {$warning}");
            }
            if ($record->vlFileNumber === '' && $record->vlNumber === '') {
                $this->log('WARN', "{$name}: skipped a record with no file or licence number");
                continue;
            }
            $records[] = $record;
        }

        $added = 0;
        $duplicates = 0;
        $this->withWorkbookLock(function () use ($records, &$added, &$duplicates): void {
            $workbook = MasterWorkbook::open($this->masterPath);
            $keys = $workbook->keys();
            foreach ($records as $record) {
                if (isset($keys[$record->key()])) {
                    $duplicates++;
                    continue;
                }
                $workbook->append($record);
                $keys[$record->key()] = true;
                $added++;
            }

            // Save before archiving so a document is never moved without its rows
            if ($added > 0) {
                $workbook->save();
            }
        });

        $this->archive($path);
        $this->log('INFO', "{$name}: {$added} record(s) added");

        return ['added' => $added, 'duplicates' => $duplicates];
    }

    /**
     * Take the "single processor" lock without waiting. Batch runs, queue drains and
     * the long-running worker all use it, so only one of them processes at a time.
     */
    public function tryLock(): bool
    {
        if ($this->lock !== null) {
            return true;
        }

        $this->ensureDir($this->incomingDir);
        $this->ensureDir($this->processedDir);

        $lock = fopen($this->incomingDir . DIRECTORY_SEPARATOR . '.agent.lock', 'c');
        if ($lock === false) {
            return false;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }

        $this->lock = $lock;

        return true;
    }

    public function __destruct()
    {
        $this->unlock();
    }

    public function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /**
     * Whether some process (this one or another) currently holds the processor lock.
     */
    public function isProcessing(): bool
    {
        if ($this->lock !== null) {
            return true;
        }
        if (!$this->tryLock()) {
            return true;
        }
        $this->unlock();

        return false;
    }

    /**
     * Whether any process holds the processor lock for this incoming folder
     * (usable without constructing an agent, e.g. from the web UI).
     */
    public static function isProcessingIn(string $incomingDir): bool
    {
        $path = rtrim($incomingDir, '/\\') . DIRECTORY_SEPARATOR . '.agent.lock';
        if (!is_file($path)) {
            return false;
        }

        $lock = fopen($path, 'c');
        if ($lock === false) {
            return false;
        }
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);

        return !$free;
    }

    /**
     * Supported documents waiting in the incoming folder, oldest name first.
     *
     * @return array<int,string> Absolute paths
     */
    public function incomingFiles(): array
    {
        $this->ensureDir($this->incomingDir);

        $files = [];
        foreach (scandir($this->incomingDir) ?: [] as $entry) {
            $path = $this->incomingDir . DIRECTORY_SEPARATOR . $entry;
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            // "~$name.docx" are Word lock files; dotfiles are sync/system files
            if ($entry[0] === '.' || strpos($entry, '~$') === 0 || !is_file($path)) {
                continue;
            }
            if (in_array($extension, self::EXTENSIONS, true)) {
                $files[] = $path;
            }
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    public function incomingDir(): string
    {
        return $this->incomingDir;
    }

    public function log(string $level, string $message): void
    {
        $this->ensureDir(dirname($this->logFile));
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $level, $message);
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Serialize workbook read-modify-write across processes (blocking).
     */
    private function withWorkbookLock(callable $callback): void
    {
        $this->ensureDir(dirname($this->masterPath));
        $lockPath = dirname($this->masterPath) . DIRECTORY_SEPARATOR . '.' . basename($this->masterPath) . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the master workbook.');
        }

        try {
            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function archive(string $file): void
    {
        $this->ensureDir($this->processedDir);
        $info = pathinfo($file);
        $target = $this->processedDir . DIRECTORY_SEPARATOR . $info['basename'];
        if (file_exists($target)) {
            $suffix = isset($info['extension']) ? '.' . $info['extension'] : '';
            $target = $this->processedDir . DIRECTORY_SEPARATOR
                . $info['filename'] . '_' . date('Ymd_His') . $suffix;
        }

        if (!rename($file, $target)) {
            throw new RuntimeException('Could not move file to ' . $this->processedDir);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
    }
}
