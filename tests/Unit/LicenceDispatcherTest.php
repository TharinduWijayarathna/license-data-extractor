<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\LicenceDispatcher;
use PHPUnit\Framework\TestCase;

class LicenceDispatcherTest extends TestCase
{
    public function testBuildsDetachedProcessCommand(): void
    {
        $command = (new LicenceDispatcher("/srv/app's dir", '/usr/bin/php'))->command();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringStartsWith('start "" /B', $command);
        } else {
            $this->assertStringContainsString("cd '/srv/app'\\''s dir' && exec nohup '/usr/bin/php' runway licences:process)", $command);
            $this->assertStringEndsWith('> /dev/null 2>&1 < /dev/null &', $command);
            $this->assertStringContainsString('exec $fd>&-', $command, 'inherited descriptors are closed');
        }
    }

    public function testDetectsACliBinary(): void
    {
        $binary = LicenceDispatcher::detectPhpBinary();

        $this->assertMatchesRegularExpression('/^php(\d+(\.\d+)?)?(\.exe)?$/i', basename($binary));
    }
}
