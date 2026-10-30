<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\LicenceAgentFactory;
use App\Utils\Config;
use App\Utils\Env;
use flight\commands\AbstractBaseCommand;

/**
 * Process new licence documents into the master workbook.
 *
 * Usage: php runway licences:process
 *
 * Designed to run unattended once a day (cron / Windows Task Scheduler).
 * Paths and the Gemini key come from config.php + .env (licences.*, gemini.*).
 */
class ProcessLicencesCommand extends AbstractBaseCommand
{
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
            $appConfig = $this->loadAppConfig($projectRoot);
            $agent = LicenceAgentFactory::fromConfig($appConfig);
            $summary = $agent->run();
        } catch (\Throwable $e) {
            $io->error($e->getMessage(), true);
            return;
        }

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
                (string) $appConfig->get('licences.log_file')
            ), true);
        }
    }

    private function loadAppConfig(string $projectRoot): Config
    {
        Env::load($projectRoot . DIRECTORY_SEPARATOR . '.env');

        $configFile = $projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';

        if (!is_file($configFile)) {
            throw new \RuntimeException(
                'app/config/config.php not found. Copy config_sample.php first.'
            );
        }

        $fileConfig = require $configFile;
        if (!is_array($fileConfig)) {
            throw new \RuntimeException('config.php must return an array.');
        }

        return new Config(Config::mergeEnv($fileConfig, $_ENV));
    }
}
