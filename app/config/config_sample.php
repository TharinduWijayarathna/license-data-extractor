<?php

/**
 * Project defaults — literal array only (Runway-safe).
 *
 * Copy to config.php (composer create-project does this for you).
 * Secrets and deploy-specific values belong in .env (see .env.example).
 *
 * Do NOT put $_ENV expressions here: `runway config:set` rewrites this
 * file as static literals and would bake resolved secrets into the file.
 *
 * Side effects (timezone, error_reporting, flight.* settings) live in
 * bootstrap.php so Runway only rewrites the data block.
 */
return [
    'app' => [
        'env' => 'development',
        'debug' => true,
        // Trailing slash is normalized at runtime (Config::baseUrl()).
        // Use '/' for app root, or a subpath like '/myapp' / '/myapp/'.
        'base_url' => '/',
        'timezone' => 'UTC',
    ],
    'database' => [
        // sqlite (default — works after create-project with no MySQL)
        // or mysql. Empty string disables DB registration and post/demo routes.
        'driver' => 'sqlite',
        'host' => 'localhost',
        'dbname' => '',
        'user' => '',
        'password' => '',
        'file_path' => __DIR__ . '/../../database.sqlite',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'prefix' => 'flight_sess_',
        // null = system temp /flight_sessions (see flightphp/session docs)
        'save_path' => null,
    ],
    'gemini' => [
        // Secret: set GEMINI_API_KEY in .env (free key from aistudio.google.com).
        'api_key' => '',
        // Pinned stable model (avoid "-latest" aliases so results don't drift)
        'model' => 'gemini-3.8-flash',
        // Gemini 3+: minimal | low | medium | high. "low" is fast and accurate for extraction.
        'thinking_level' => 'low',
        'timeout' => 120,
    ],
    'licences' => [
        // Point these at a synced OneDrive / network folder in .env if needed.
        'incoming_dir' => __DIR__ . '/../../storage/licences/incoming',
        'processed_dir' => __DIR__ . '/../../storage/licences/processed',
        'master_xlsx' => __DIR__ . '/../../storage/licences/Master_Licences.xlsx',
        'log_file' => __DIR__ . '/../log/licence_agent.log',
    ],
    'runway' => [
        'index_root' => 'public/index.php',
        'app_root' => 'app/',
    ],
];
