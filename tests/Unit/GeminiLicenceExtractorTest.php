<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\DocxTextExtractor;
use App\Service\GeminiLicenceExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GeminiLicenceExtractorTest extends TestCase
{
    public function testRequiresApiKey(): void
    {
        $this->expectException(RuntimeException::class);
        new GeminiLicenceExtractor('', 'gemini-2.5-flash', 30, new DocxTextExtractor());
    }

    public function testPdfPayloadUsesInlineDataAndJsonSchema(): void
    {
        $pdf = sys_get_temp_dir() . '/gemini_test_' . uniqid() . '.pdf';
        file_put_contents($pdf, '%PDF-1.4 fake');

        try {
            $payload = (new GeminiLicenceExtractor('key', 'm', 30, new DocxTextExtractor()))->buildPayload($pdf);
        } finally {
            unlink($pdf);
        }

        $this->assertSame(
            ['mime_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 fake')],
            $payload['contents'][0]['parts'][0]['inline_data']
        );
        $this->assertSame('application/json', $payload['generationConfig']['responseMimeType']);
        $this->assertSame(GeminiLicenceExtractor::RESPONSE_SCHEMA, $payload['generationConfig']['responseSchema']);
        $this->assertArrayNotHasKey('temperature', $payload['generationConfig']);
    }

    public function testThinkingLevelOnlySentToGemini3Models(): void
    {
        $docx = $this->docx();
        try {
            $new = (new GeminiLicenceExtractor('key', 'gemini-3.8-flash', 30, new DocxTextExtractor(), 'low'))->buildPayload($docx);
            $old = (new GeminiLicenceExtractor('key', 'gemini-2.5-flash', 30, new DocxTextExtractor(), 'low'))->buildPayload($docx);
        } finally {
            unlink($docx);
        }

        $this->assertSame(['thinkingLevel' => 'low'], $new['generationConfig']['thinkingConfig']);
        $this->assertArrayNotHasKey('thinkingConfig', $old['generationConfig']);
        $this->assertStringContainsString('0881/26/R', $new['contents'][0]['parts'][0]['text']);
    }

    private function docx(): string
    {
        $path = sys_get_temp_dir() . '/gemini_test_' . uniqid() . '.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>0881/26/R LCS/VEN/4315/2024</w:t></w:r></w:p></w:body></w:document>'
        );
        $zip->close();

        return $path;
    }

    public function testRejectsUnsupportedExtension(): void
    {
        $this->expectException(RuntimeException::class);
        (new GeminiLicenceExtractor('key', 'm', 30, new DocxTextExtractor()))->buildPayload('/tmp/file.doc');
    }

    public function testParsesRecordsFromCandidateText(): void
    {
        $records = [['VLNumber' => '0881/26/R'], ['VLNumber' => '0882/26/N']];
        $body = json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($records)]]],
                'finishReason' => 'STOP',
            ]],
        ]);

        $this->assertSame($records, GeminiLicenceExtractor::parseResponse((string) $body));
    }

    public function testWrapsSingleObjectResponse(): void
    {
        $body = json_encode([
            'candidates' => [['content' => ['parts' => [['text' => '{"VLNumber":"1"}']]]]],
        ]);

        $this->assertSame([['VLNumber' => '1']], GeminiLicenceExtractor::parseResponse((string) $body));
    }

    public function testBlockedPromptRaises(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAFETY');
        GeminiLicenceExtractor::parseResponse('{"promptFeedback":{"blockReason":"SAFETY"}}');
    }
}
