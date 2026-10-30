<?php

declare(strict_types=1);

namespace Tests\Support;

use flight\database\SimplePdo;
use PDO;

/**
 * SQLite database with the licence_jobs migration applied, for queue tests.
 */
final class QueueDatabase
{
    public static function create(string $dsn = 'sqlite::memory:'): SimplePdo
    {
        $db = new SimplePdo($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/20261031120000_create_licence_jobs.sql');
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $db->exec($statement);
        }

        return $db;
    }
}
