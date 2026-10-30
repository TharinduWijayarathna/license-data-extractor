<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\LicenceAgent;
use App\Service\LicenceExtractor;
use App\Service\LicenceNormalizer;
use App\Service\MasterWorkbook;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LicenceAgentTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/licence_agent_' . uniqid();
        mkdir($this->root . '/incoming', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testProcessesFilesDeduplicatesAndArchives(): void
    {
        touch($this->root . '/incoming/a.pdf');
        touch($this->root . '/incoming/b.docx');
        touch($this->root . '/incoming/~$b.docx');   // Word lock file — ignored
        touch($this->root . '/incoming/notes.txt');  // unsupported — ignored

        $extractor = $this->fakeExtractor([
            'a.pdf' => [self::raw('LCS/VEN/1/2026', '0001/26/N'), self::raw('LCS/VEN/2/2026', '0002/26/N')],
            // Same licence again (with noise) plus one new
            'b.docx' => [self::raw('LCS/VEN/1/2026', '..0001/26/N'), self::raw('LCS/VEN/3/2026', '0003/26/R')],
        ]);

        $summary = $this->agent($extractor)->run();

        $this->assertSame(2, $summary['files']);
        $this->assertSame(2, $summary['processed']);
        $this->assertSame(3, $summary['added']);
        $this->assertSame(1, $summary['duplicates']);
        $this->assertFileExists($this->root . '/processed/a.pdf');
        $this->assertFileExists($this->root . '/processed/b.docx');
        $this->assertFileDoesNotExist($this->root . '/incoming/a.pdf');
        $this->assertFileExists($this->root . '/incoming/notes.txt');

        $rows = MasterWorkbook::open($this->root . '/Master.xlsx')->rows();
        $this->assertCount(3, $rows);
        $this->assertSame('0003/26/R', $rows[2]['VLNumber']);
        $this->assertSame('01/10/2027', $rows[0]['ExpireDate']);
        $this->assertSame("NO. 1, MAIN STREET, MATARA.\nNO. 2, BEACH ROAD, GALLE.", $rows[0]['Address1']);

        // Layout rules survive a save/reload
        $sheet = IOFactory::load($this->root . '/Master.xlsx')->getSheetByName(MasterWorkbook::SHEET);
        $this->assertNotNull($sheet);
        $this->assertSame(Alignment::HORIZONTAL_RIGHT, $sheet->getStyle('E2')->getAlignment()->getHorizontal());
        $this->assertTrue($sheet->getStyle('D2')->getAlignment()->getWrapText());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('C2')->getAlignment()->getHorizontal());
        $this->assertSame('dd/mm/yyyy', $sheet->getStyle('E2')->getNumberFormat()->getFormatCode());
    }

    public function testFailedFileStaysInIncomingAndIsLogged(): void
    {
        touch($this->root . '/incoming/bad.pdf');
        touch($this->root . '/incoming/good.pdf');

        $extractor = $this->fakeExtractor([
            'bad.pdf' => new RuntimeException('Gemini API request failed (HTTP 429)'),
            'good.pdf' => [self::raw('LCS/VEN/9/2026', '0009/26/N')],
        ]);

        $summary = $this->agent($extractor)->run();

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['added']);
        $this->assertFileExists($this->root . '/incoming/bad.pdf');
        $this->assertFileExists($this->root . '/processed/good.pdf');
        $this->assertStringContainsString('ERROR bad.pdf: Gemini API request failed', (string) file_get_contents($this->root . '/agent.log'));
    }

    public function testExistingWorkbookRowsAreNotDuplicatedOnLaterRuns(): void
    {
        touch($this->root . '/incoming/a.pdf');
        $files = ['a.pdf' => [self::raw('LCS/VEN/1/2026', '0001/26/N')]];
        $this->agent($this->fakeExtractor($files))->run();

        touch($this->root . '/incoming/a.pdf');
        $summary = $this->agent($this->fakeExtractor($files))->run();

        $this->assertSame(0, $summary['added']);
        $this->assertSame(1, $summary['duplicates']);
        $this->assertCount(1, MasterWorkbook::open($this->root . '/Master.xlsx')->rows());
        // Second copy archived under a unique name
        $this->assertCount(2, glob($this->root . '/processed/a*.pdf') ?: []);
    }

    public function testNoFilesDoesNotCreateWorkbook(): void
    {
        $summary = $this->agent($this->fakeExtractor([]))->run();

        $this->assertSame(0, $summary['files']);
        $this->assertFileDoesNotExist($this->root . '/Master.xlsx');
    }

    private function agent(LicenceExtractor $extractor): LicenceAgent
    {
        return new LicenceAgent(
            $extractor,
            new LicenceNormalizer(),
            $this->root . '/incoming',
            $this->root . '/processed',
            $this->root . '/Master.xlsx',
            $this->root . '/agent.log'
        );
    }

    /**
     * @param array<string,array<int,array<string,mixed>>|RuntimeException> $byFile
     */
    private function fakeExtractor(array $byFile): LicenceExtractor
    {
        return new class ($byFile) implements LicenceExtractor {
            /** @var array<string,array<int,array<string,mixed>>|RuntimeException> */
            private $byFile;

            /**
             * @param array<string,array<int,array<string,mixed>>|RuntimeException> $byFile
             */
            public function __construct(array $byFile)
            {
                $this->byFile = $byFile;
            }

            public function extract(string $path): array
            {
                $result = $this->byFile[basename($path)] ?? [];
                if ($result instanceof RuntimeException) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @return array<string,mixed>
     */
    private static function raw(string $file, string $number): array
    {
        return [
            'VLFileNumber' => $file,
            'VLNumber' => $number,
            'CompanyName' => 'TEST MOBILE (PVT) LTD',
            'Addresses' => ['NO. 1, MAIN STREET, MATARA', 'NO. 2, BEACH ROAD, GALLE'],
            'EffectiveDate' => '02/10/2026',
            'ExpireDate' => '',
            'Wired' => true,
            'Wireless' => true,
            'Exclusions' => ['SAT', 'GPSVT'],
            'Districts' => ['MATARA', 'GALLE'],
        ];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
