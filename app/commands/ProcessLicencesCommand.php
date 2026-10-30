<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\LicenceAgentFactory;
use App\Service\LicenceWorker;
use App\Utils\AppConfigLoader;
use flight\commands\AbstractBaseCommand;

/**
 * Process new licence documents into the master workbook, then exit.
 *
 * Usage: php runway licences:process
 *
 * With the job queue (database migrated) this queues new documents and works
 * through every due job, waiting for short retry back-offs. Without it, it
 * processes the incoming folder in one batch. Safe to run from cron / Task
 * Scheduler and from the web UI at the same time: only one run processes at once.
 */
class ProcessLicencesCommand extends AbstractBaseCommand
{
    /** Wait for retries that become due within this many seconds before exiting. */
    private const MAX_RETRY_WAIT = 300;

    /**
     * @param array<string,mixed> $config From .runway-config.json
     */
    public function __construct(array $config)
    {
        parent::__construct('licences:process', 'Extract licence documents from the incoming folder into the master Excel file', $config);
    }

    public function execute(): void
    {
        $io = $this->io();
        $projectRoot = getcwd();
        if ($projectRoot === false) {
            $io->error('Unable to determine project root (getcwd failed).', true);
            return;
        }

        try {
            $appConfig = AppConfigLoader::load($projectRoot);
            $agent = LicenceAgentFactory::fromConfig($appConfig);
            $queue = LicenceAgentFactory::queue($appConfig);
        } catch (\Throwable $e) {
            $io->error($e->getMessage(), true);
            return;
        }

        if ($queue === null) {
            $this->runBatch($agent, (string) $appConfig->get('licences.log_file'));
            return;
        }

        if (!$agent->tryLock()) {
            $io->info('A worker or another run is already processing; new documents are queued for it.', true);
            return;
        }

        $worker = new LicenceWorker($agent, $queue);
        $totals = ['processed' => 0, 'failed' => 0, 'retrying' => 0, 'added' => 0, 'duplicates' => 0];
        try {
            do {
                foreach ($worker->drain() as $key => $value) {
                    $totals[$key] += $value;
                }
                // Wait for back-offs that are due soon instead of leaving them for the next run
                $dueIn = $queue->nextDueIn();
                if ($dueIn !== null && $dueIn > 0 && $dueIn <= self::MAX_RETRY_WAIT) {
                    $io->comment("Waiting {$dueIn}s for a retry…", true);
                    sleep($dueIn);
                }
            } while ($dueIn !== null && $dueIn <= self::MAX_RETRY_WAIT);
        } finally {
            $agent->unlock();
        }

        if ($totals['processed'] + $totals['failed'] === 0) {
            $io->info('No documents waiting.', true);
            return;
        }

        $io->ok(sprintf(
            'Processed %d document(s): %d record(s) added, %d duplicate(s) skipped.',
            $totals['processed'],
            $totals['added'],
            $totals['duplicates']
        ), true);
        if ($totals['failed'] > 0) {
            $io->error(sprintf(
                '%d document(s) failed after %d attempts — see %s or retry them from the dashboard.',
                $totals['failed'],
                $queue->maxAttempts(),
                (string) $appConfig->get('licences.log_file')
            ), true);
        }
    }

    /**
     * Original batch mode, used when the queue table is not available.
     */
    private function runBatch(\App\Service\LicenceAgent $agent, string $logFile): void
    {
        $io = $this->io();
        $summary = $agent->run();

        if ($summary['locked']) {
            $io->warn('Another run is in progress; skipped.', true);
            return;
        }
        if ($summary['files'] === 0) {
            $io->info('No new documents.', true);
            return;
        }

        $io->ok(sprintf(
            'Processed %d of %d file(s): %d record(s) added, %d duplicate(s) skipped.',
            $summary['processed'],
            $summary['files'],
            $summary['added'],
            $summary['duplicates']
        ), true);

        if ($summary['failed'] > 0) {
            $io->error(sprintf(
                '%d file(s) failed and were left in the incoming folder — see %s',
                $summary['failed'],
                $logFile
            ), true);
        }
    }
}
