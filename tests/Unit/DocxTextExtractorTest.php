<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\DocxTextExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class DocxTextExtractorTest extends TestCase
{
    /** @var string */
    private $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/docx_test_' . uniqid() . '.docx';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testExtractsHeaderParagraphsAndTableRows(): void
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $body = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<w:document {$w}><w:body>
  <w:p><w:r><w:t>FILE NO: LCS/VEN/5071/2026</w:t></w:r></w:p>
  <w:tbl>
    <w:tr><w:tc><w:p><w:r><w:t>LICENCE NO</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>0882/26/N</w:t></w:r></w:p></w:tc></w:tr>
    <w:tr><w:tc><w:p><w:r><w:t>ADDRESS</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>NO. 99,</w:t></w:r><w:r><w:br/><w:t>HORANA.</w:t></w:r></w:p></w:tc></w:tr>
  </w:tbl>
</w:body></w:document>
XML;
        $header = "<?xml version=\"1.0\"?><w:hdr {$w}><w:p><w:r><w:t>TRCSL</w:t></w:r></w:p></w:hdr>";

        $zip = new ZipArchive();
        $zip->open($this->path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', $body);
        $zip->addFromString('word/header1.xml', $header);
        $zip->close();

        $text = (new DocxTextExtractor())->extract($this->path);

        $this->assertSame(
            "TRCSL\nFILE NO: LCS/VEN/5071/2026\nLICENCE NO | 0882/26/N\nADDRESS | NO. 99, HORANA.",
            $text
        );
    }

    public function testRejectsNonDocxFile(): void
    {
        file_put_contents($this->path, 'not a zip');

        $this->expectException(RuntimeException::class);
        (new DocxTextExtractor())->extract($this->path);
    }
}
