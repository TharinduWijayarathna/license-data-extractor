<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * The incoming folder as seen by the web UI: what is waiting to be processed,
 * and validated storage for uploaded documents.
 */
final class LicenceInbox
{
    /** Leading bytes of each accepted type (.docx is a ZIP container). */
    private const SIGNATURES = ['pdf' => '%PDF-', 'docx' => "PK\x03\x04"];

    /** @var string */
    private $dir;

    /** @var int */
    private $maxBytes;

    public function __construct(string $dir, int $maxBytes)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->maxBytes = $maxBytes;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Documents waiting for the next run, oldest first.
     *
     * @return array<int,array{name:string,size:int,modified:int}>
     */
    public function pending(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            $path = $this->dir . DIRECTORY_SEPARATOR . $entry;
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($entry[0] === '.' || strpos($entry, '~$') === 0 || !is_file($path)
                || !in_array($extension, LicenceAgent::EXTENSIONS, true)) {
                continue;
            }
            $files[] = ['name' => $entry, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
        }

        usort($files, function (array $a, array $b): int {
            return [$a['modified'], $a['name']] <=> [$b['modified'], $b['name']];
        });

        return $files;
    }

    /**
     * Validate an uploaded document and move it into the incoming folder.
     *
     * @param bool $isHttpUpload false only in tests (skips is_uploaded_file)
     * @return string The stored file name
     * @throws RuntimeException With a message safe to show the user
     */
    public function store(string $clientName, string $tmpPath, int $error, int $size, bool $isHttpUpload = true): string
    {
        $name = self::safeName($clientName);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException("{$name}: file is larger than the server upload limit.");
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException("{$name}: upload failed (error {$error}).");
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::SIGNATURES[$extension])) {
            throw new RuntimeException("{$name}: only .docx and .pdf files are accepted.");
        }
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new RuntimeException(sprintf('%s: file must be under %d MB.', $name, intdiv($this->maxBytes, 1048576)));
        }

        $head = (string) @file_get_contents($tmpPath, false, null, 0, 5);
        if (strpos($head, self::SIGNATURES[$extension]) !== 0) {
            throw new RuntimeException("{$name}: file content is not a valid .{$extension}.");
        }

        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('The incoming folder could not be created.');
        }

        $target = $this->uniquePath($name);
        $moved = $isHttpUpload ? move_uploaded_file($tmpPath, $target) : rename($tmpPath, $target);
        if (!$moved) {
            throw new RuntimeException("{$name}: could not be saved.");
        }

        return basename($target);
    }

    /**
     * Validate raw file contents (e.g. a base64 upload decoded by the API) and
     * store them in the incoming folder, with the same checks as a form upload.
     *
     * @return string The stored file name
     * @throws RuntimeException With a message safe to show the user
     */
    public function storeContents(string $clientName, string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'licence_');
        if ($tmp === false || file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException(self::safeName($clientName) . ': could not be saved.');
        }
        // tempnam() creates the file 0600; give it the permissions a normal upload would have
        @chmod($tmp, 0666 & ~umask());

        try {
            return $this->store($clientName, $tmp, UPLOAD_ERR_OK, strlen($contents), false);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Strip any path and characters that are unsafe in file names on Windows, macOS or Linux.
     */
    public static function safeName(string $clientName): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        $name = (string) preg_replace('/[^\w\s().,&+-]/u', '_', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .');

        return $name === '' ? 'document' : $name;
    }

    private function uniquePath(string $name): string
    {
        $info = pathinfo($name);
        $suffix = isset($info['extension']) ? '.' . $info['extension'] : '';
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;

        for ($i = 2; file_exists($path); $i++) {
            $path = $this->dir . DIRECTORY_SEPARATOR . $info['filename'] . " ({$i})" . $suffix;
        }

        return $path;
    }
}
