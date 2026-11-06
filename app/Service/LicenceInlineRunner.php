<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\Config;

/**
 * Processes the queue inside the web request's own PHP process, after the HTTP
 * response has been sent (licences.queue.dispatch = inline).
 *
 * For shared hosting (cPanel / LiteSpeed / Imunify360) where spawning a shell
 * from PHP is blocked or killed: no exec(), no cron. The client gets its 202
 * at once; the PHP worker keeps running until the queue is empty.
 */
final class LicenceInlineRunner
{
    /**
     * Run once the response is out. Meant to be registered with
     * register_shutdown_function(), which runs after Flight has echoed the body.
     */
    public static function afterResponse(Config $config): void
    {
        // Keep going when the client disconnects, and do not stop at max_execution_time
        ignore_user_abort(true);
        @set_time_limit(0);

        self::finishRequest();

        try {
            self::run($config);
        } catch (\Throwable $e) {
            error_log('Licence inline run failed: ' . $e->getMessage());
        }
    }

    /**
     * Flush and close the HTTP response so the client is not kept waiting.
     */
    public static function finishRequest(): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        // LiteSpeed (lsphp, typical on cPanel) and php-fpm can end the response early
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Same work as `php runway licences:process`: drain the queue (or run the
     * batch without a database). Skips quietly if another run holds the lock.
     *
     * @return bool Whether this call did the processing (false = another run had the lock)
     */
    public static function run(Config $config): bool
    {
        $agent = LicenceAgentFactory::fromConfig($config);
        $queue = LicenceAgentFactory::queue($config);

        if ($queue === null) {
            return !$agent->run()['locked'];
        }

        if (!$agent->tryLock()) {
            return false;
        }
        try {
            (new LicenceWorker($agent, $queue))->drainUntilIdle(LicenceWorker::MAX_RETRY_WAIT);
        } finally {
            $agent->unlock();
        }

        return true;
    }
}
