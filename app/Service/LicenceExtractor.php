<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns one licence document into raw (un-normalized) record arrays.
 */
interface LicenceExtractor
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function extract(string $path): array;
}
