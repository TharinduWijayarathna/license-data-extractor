<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Starts a detached `php runway licences:process` so the web request can return
 * immediately. If a worker or another run already holds the processor lock, the
 * spawned process exits at once, so dispatching repeatedly is harmless.
 */
class LicenceDispatcher
{
    /** @var string */
    private $projectRoot;

    /** @var string */
    private $phpBinary;

    public function __construct(string $projectRoot, string $phpBinary = '')
    {
        $this->projectRoot = $projectRoot;
        $this->phpBinary = $phpBinary !== '' ? $phpBinary : self::detectPhpBinary();
    }

    public function dispatch(): bool
    {
        $command = $this->command();
        if (PHP_OS_FAMILY === 'Windows') {
            $handle = popen($command, 'r');
            if ($handle === false) {
                return false;
            }
            pclose($handle);

            return true;
        }

        exec($command, $output, $code);

        return $code === 0;
    }

    /**
     * Shell command that runs the queue in the background, detached from this request.
     */
    public function command(): string
    {
        $php = escapeshellarg($this->phpBinary);
        $root = escapeshellarg($this->projectRoot);

        if (PHP_OS_FAMILY === 'Windows') {
            return 'start "" /B /D ' . $root . ' ' . $php . ' runway licences:process';
        }

        // Close every descriptor inherited from the web server (the client socket in
        // particular) so the HTTP response is not held open until the run finishes.
        $closeInherited = 'for fd in $(ls /dev/fd); do [ "$fd" -gt 2 ] 2>/dev/null && eval "exec $fd>&-"; done';

        return '(' . $closeInherited . '; cd ' . $root . ' && exec nohup ' . $php . ' runway licences:process)'
            . ' > /dev/null 2>&1 < /dev/null &';
    }

    /**
     * The CLI binary: PHP_BINARY is php-fpm (or similar) under a web server, so
     * fall back to the CLI that ships alongside it.
     */
    public static function detectPhpBinary(): string
    {
        $isCli = static function (string $path): bool {
            // php, php.exe, or versioned CLIs such as php8.4 / php85 (Herd, Homebrew) — not php-fpm / php-cgi
            return $path !== '' && is_file($path)
                && preg_match('/^php(\d+(\.\d+)?)?(\.exe)?$/i', basename($path)) === 1;
        };

        if ($isCli(PHP_BINARY)) {
            return PHP_BINARY;
        }
        foreach ([PHP_BINDIR . DIRECTORY_SEPARATOR . 'php', PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe'] as $candidate) {
            if ($isCli($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}
