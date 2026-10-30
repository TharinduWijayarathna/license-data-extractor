<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class TwigTemplatesTest extends TestCase
{
    public function testAllTemplatesCompile(): void
    {
        $viewsDir = dirname(__DIR__, 2) . '/app/views';
        $twig = new Environment(new FilesystemLoader($viewsDir));
        // Functions registered in services.php
        $twig->addFunction(new TwigFunction('csrf_token', function (): string {
            return 'token';
        }));

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsDir));
        $compiled = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }
            $name = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($viewsDir))), '/');
            $twig->compileSource($twig->getLoader()->getSourceContext($name));
            $compiled++;
        }

        $this->assertGreaterThan(0, $compiled);
    }
}
