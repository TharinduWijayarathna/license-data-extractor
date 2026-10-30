<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\Config;
use App\Utils\DatabaseFactory;

/**
 * Builds the licence agent from config so the CLI command and the web UI
 * run exactly the same pipeline.
 */
final class LicenceAgentFactory
{
    /**
     * @throws \RuntimeException When GEMINI_API_KEY is missing
     */
    public static function fromConfig(Config $config): LicenceAgent
    {
        return new LicenceAgent(
            new GeminiLicenceExtractor(
                (string) $config->get('gemini.api_key', ''),
                (string) $config->get('gemini.model', 'gemini-3.8-flash'),
                (int) $config->get('gemini.timeout', 120),
                new DocxTextExtractor(),
                (string) $config->get('gemini.thinking_level', 'low')
            ),
            new LicenceNormalizer(),
            (string) $config->get('licences.incoming_dir'),
            (string) $config->get('licences.processed_dir'),
            (string) $config->get('licences.master_xlsx'),
            (string) $config->get('licences.log_file')
        );
    }

    /**
     * The job queue, or null when the database is disabled or not migrated yet;
     * callers then fall back to the synchronous batch run.
     */
    public static function queue(Config $config): ?LicenceQueue
    {
        if (!DatabaseFactory::isEnabled($config)) {
            return null;
        }

        try {
            $db = DatabaseFactory::create($config);
            $db->fetchColumn('SELECT COUNT(*) FROM licence_jobs');
        } catch (\Throwable $e) {
            return null; // e.g. "no such table" before `php runway migrate`
        }

        return new LicenceQueue(
            $db,
            (int) $config->get('licences.queue.max_attempts', 3),
            (int) $config->get('licences.queue.retry_after', 60),
            (int) $config->get('licences.queue.stale_after', 900)
        );
    }

    public static function dispatcher(Config $config): LicenceDispatcher
    {
        return new LicenceDispatcher(
            dirname(__DIR__, 2),
            (string) $config->get('licences.queue.php_binary', '')
        );
    }

    public static function hasApiKey(Config $config): bool
    {
        return trim((string) $config->get('gemini.api_key', '')) !== '';
    }
}
