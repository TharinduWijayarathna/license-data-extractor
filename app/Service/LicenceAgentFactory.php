<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\Config;

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

    public static function hasApiKey(Config $config): bool
    {
        return trim((string) $config->get('gemini.api_key', '')) !== '';
    }
}
