<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\LicenceAgentFactory;
use App\Service\LicenceWorker;
use App\Utils\AppConfigLoader;
use flight\commands\AbstractBaseCommand;

/**
 * Long-running queue worker: picks up documents as soon as they arrive.
 *
 * Usage: php runway licences:work [--sleep=5] [--max-runtime=0]
 *
 * Run under a process manager (systemd, Supervisor, NSSM on Windows). Stops
 * cleanly on SIGTERM / Ctrl+C after the current document. While it runs, web
 * uploads and cron runs leave the work to it.
 */
class WorkLicencesCommand extends AbstractBaseCommand
{
    /** @var bool */
    private $stopping = false;

    /**
     * @param array<string,mixed> $config From .runway-config.json
     */
    public function __construct(array $config)
    {
        parent::__construct('licences:work', 'Run the licence queue worker continuously', $config);
        $this->option('-s --sleep', 'Seconds to wait when the queue is empty', 'intval', 5);
        $this->option('-m --max-runtime', 'Exit after this many seconds (0 = run until stopped)', 'intval', 0);
    }

    public function execute(): void
    {
        $io = $this->io();
        $values = $this->values();
        $sleep = max(1, (int) ($values['sleep'] ?? 5));
        $maxRuntime = max(0, (int) ($values['maxRuntime'] ?? 0));

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
            $io->error('The job queue needs the database. Set DB_DRIVER and run `php runway migrate`.', true);
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function (): void {
                $this->stopping = true;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        $io->info('Waiting for the processor lock…', true);
        while (!$agent->tryLock()) {
            if ($this->shouldStop()) {
                return;
            }
            sleep($sleep);
        }

        $worker = new LicenceWorker($agent, $queue);
        $startedAt = time();
        $io->ok("Worker started (polling every {$sleep}s). Press Ctrl+C to stop.", true);

        try {
            while (!$this->shouldStop() && ($maxRuntime === 0 || time() - $startedAt < $maxRuntime)) {
                $summary = $worker->drain(function (): bool {
                    return $this->shouldStop();
                });

                if ($summary['processed'] + $summary['failed'] + $summary['retrying'] > 0) {
                    $io->info(sprintf(
                        '[%s] %d processed, %d added, %d duplicate(s), %d retrying, %d failed',
                        date('H:i:s'),
                        $summary['processed'],
                        $summary['added'],
                        $summary['duplicates'],
                        $summary['retrying'],
                        $summary['failed']
                    ), true);
                }

                for ($i = 0; $i < $sleep && !$this->shouldStop(); $i++) {
                    sleep(1);
                }
            }
        } finally {
            $agent->unlock();
        }

        $io->info('Worker stopped.', true);
    }

    /**
     * Set asynchronously by the SIGTERM / SIGINT handler.
     *
     * @phpstan-impure
     */
    private function shouldStop(): bool
    {
        return $this->stopping;
    }
}
