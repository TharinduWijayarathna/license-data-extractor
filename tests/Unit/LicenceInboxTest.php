<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\LicenceInbox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LicenceInboxTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/licence_inbox_' . uniqid();
        mkdir($this->dir . '/tmp', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/tmp/*') ?: [], glob($this->dir . '/*.*') ?: []) as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/tmp');
        rmdir($this->dir);
    }

    public function testStoresValidFilesWithSafeUniqueNames(): void
    {
        $inbox = new LicenceInbox($this->dir, 1048576);

        $first = $inbox->store('C:\\Users\\me\\0881-26-R (SAMARA)-B&W.docx', $this->tmp("PK\x03\x04data"), UPLOAD_ERR_OK, 8, false);
        $second = $inbox->store('0881-26-R (SAMARA)-B&W.docx', $this->tmp("PK\x03\x04data"), UPLOAD_ERR_OK, 8, false);
        $pdf = $inbox->store('../../etc/scan?.pdf', $this->tmp('%PDF-1.7'), UPLOAD_ERR_OK, 8, false);

        $this->assertSame('0881-26-R (SAMARA)-B&W.docx', $first);
        $this->assertSame('0881-26-R (SAMARA)-B&W (2).docx', $second);
        $this->assertSame('scan_.pdf', $pdf);
        $this->assertEqualsCanonicalizing(
            [$first, $second, $pdf],
            array_column($inbox->pending(), 'name')
        );
    }

    public function testStoreContentsAppliesTheSameChecks(): void
    {
        $inbox = new LicenceInbox($this->dir, 1048576);

        $this->assertSame('api.pdf', $inbox->storeContents('../api.pdf', '%PDF-1.7 body'));
        $this->assertSame('%PDF-1.7 body', file_get_contents($this->dir . '/api.pdf'));

        try {
            $inbox->storeContents('fake.pdf', '<?php echo 1;');
            $this->fail('Spoofed content must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not a valid .pdf', $e->getMessage());
        }
        $this->assertSame(['api.pdf'], array_column($inbox->pending(), 'name'));
    }

    /**
     * @return array<string,array{0:string,1:string,2:int,3:int,4:string}>
     */
    public static function rejectedUploads(): array
    {
        return [
            'wrong extension' => ['notes.txt', 'hello', UPLOAD_ERR_OK, 5, 'only .docx and .pdf'],
            'legacy .doc' => ['old.doc', "\xD0\xCF\x11\xE0", UPLOAD_ERR_OK, 4, 'only .docx and .pdf'],
            'spoofed content' => ['fake.pdf', '<?php echo 1;', UPLOAD_ERR_OK, 13, 'not a valid .pdf'],
            'too large' => ['big.pdf', '%PDF-', UPLOAD_ERR_OK, 2048, 'must be under'],
            'php ini limit' => ['big.pdf', '', UPLOAD_ERR_INI_SIZE, 0, 'upload limit'],
            'empty' => ['empty.pdf', '', UPLOAD_ERR_OK, 0, 'must be under'],
        ];
    }

    #[DataProvider('rejectedUploads')]
    public function testRejectsInvalidUploads(string $name, string $content, int $error, int $size, string $message): void
    {
        $inbox = new LicenceInbox($this->dir, 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $inbox->store($name, $this->tmp($content), $error, $size, false);
    }

    private function tmp(string $content): string
    {
        $path = $this->dir . '/tmp/' . uniqid('up', true);
        file_put_contents($path, $content);

        return $path;
    }
}
