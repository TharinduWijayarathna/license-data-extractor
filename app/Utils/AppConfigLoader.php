<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Builds Config for CLI commands, which run without the web bootstrap
 * (same steps as bootstrap.php: .env, config.php, env overlay).
 */
final class AppConfigLoader
{
    public static function load(string $projectRoot): Config
    {
        Env::load($projectRoot . DIRECTORY_SEPARATOR . '.env');

        $configFile = $projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';

        if (!is_file($configFile)) {
            throw new \RuntimeException('app/config/config.php not found. Copy config_sample.php first.');
        }

        $fileConfig = require $configFile;
        if (!is_array($fileConfig)) {
            throw new \RuntimeException('config.php must return an array.');
        }

        $timezone = (string) ($fileConfig['app']['timezone'] ?? 'UTC');
        $config = new Config(Config::mergeEnv($fileConfig, $_ENV));
        date_default_timezone_set((string) $config->get('app.timezone', $timezone));

        return $config;
    }
}
