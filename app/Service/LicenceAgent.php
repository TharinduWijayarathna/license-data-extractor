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
     * @return array{files:int, processed:int, failed:int, added:int, duplicates:int, locked:bool}
     */
    public function run(): array
    {
        $summary = ['files' => 0, 'processed' => 0, 'failed' => 0, 'added' => 0, 'duplicates' => 0, 'locked' => false];

        $this->ensureDir($this->incomingDir);
        $this->ensureDir($this->processedDir);

        // Prevent two overlapping runs (e.g. scheduler + manual) writing the workbook
        $lock = fopen($this->incomingDir . DIRECTORY_SEPARATOR . '.agent.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->log('WARN', 'Another run is in progress; skipping.');
            $summary['locked'] = true;
            return $summary;
        }

        try {
            $files = $this->incomingFiles();
            $summary['files'] = count($files);
            if ($files === []) {
                return $summary;
            }

            $workbook = MasterWorkbook::open($this->masterPath);
            $keys = $workbook->keys();

            foreach ($files as $file) {
                $name = basename($file);
                try {
                    $added = 0;
                    foreach ($this->extractor->extract($file) as $raw) {
                        $record = $this->normalizer->normalize($raw);
                        foreach ($this->normalizer->warnings() as $warning) {
                            $this->log('WARN', "{$name}: {$record->vlNumber}: {$warning}");
                        }

                        if ($record->vlFileNumber === '' && $record->vlNumber === '') {
                            $this->log('WARN', "{$name}: skipped a record with no file or licence number");
                            continue;
                        }
                        if (isset($keys[$record->key()])) {
                            $summary['duplicates']++;
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
                    $this->archive($file);

                    $summary['processed']++;
                    $summary['added'] += $added;
                    $this->log('INFO', "{$name}: {$added} record(s) added");
                } catch (Throwable $e) {
                    $summary['failed']++;
                    $this->log('ERROR', "{$name}: " . $e->getMessage());
                    // Reload so a failed save does not leave unsaved rows behind
                    $workbook = MasterWorkbook::open($this->masterPath);
                    $keys = $workbook->keys();
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $summary;
    }

    /**
     * @return array<int,string>
     */
    private function incomingFiles(): array
    {
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

    private function archive(string $file): void
    {
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

    private function log(string $level, string $message): void
    {
        $this->ensureDir(dirname($this->logFile));
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $level, $message);
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
