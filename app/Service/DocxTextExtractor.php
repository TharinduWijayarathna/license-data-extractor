<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use ZipArchive;

/**
 * Pulls readable text out of a .docx (headers + body), keeping table rows on
 * one line with " | " between cells so the AI can see the field layout.
 *
 * Gemini does not accept .docx uploads directly, so Word files are sent as text.
 */
final class DocxTextExtractor
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function extract(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Not a readable .docx file: ' . basename($path));
        }

        try {
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^word/header\d*\.xml$#', $name)) {
                    $parts[] = $this->partText((string) $zip->getFromIndex($i));
                }
            }

            $body = $zip->getFromName('word/document.xml');
            if ($body === false) {
                throw new RuntimeException('Missing word/document.xml in ' . basename($path));
            }
            $parts[] = $this->partText($body);
        } finally {
            $zip->close();
        }

        return trim(implode("\n", array_filter($parts, function (string $part): bool {
            return $part !== '';
        })));
    }

    private function partText(string $xml): string
    {
        if (trim($xml) === '') {
            return '';
        }

        $dom = new DOMDocument();
        // No LIBXML_NOENT: never expand entities from untrusted documents
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return '';
        }

        $root = $dom->documentElement;
        if ($root === null) {
            return '';
        }

        // document.xml wraps content in <w:body>; headers use <w:hdr> directly
        $bodies = $root->getElementsByTagNameNS(self::NS_W, 'body');
        $container = $bodies->length > 0 ? $bodies->item(0) : $root;
        if ($container === null) {
            return '';
        }

        return trim(implode("\n", $this->blocks($container)));
    }

    /**
     * @return array<int,string>
     */
    private function blocks(DOMNode $container): array
    {
        $lines = [];
        foreach ($container->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== self::NS_W) {
                continue;
            }

            if ($child->localName === 'p') {
                $text = trim($this->paragraphText($child));
                if ($text !== '') {
                    $lines[] = $text;
                }
            } elseif ($child->localName === 'tbl') {
                foreach ($this->tableRows($child) as $row) {
                    $lines[] = $row;
                }
            } elseif ($child->localName === 'sdt') {
                // Content controls wrap ordinary paragraphs/tables
                foreach ($child->getElementsByTagNameNS(self::NS_W, 'sdtContent') as $content) {
                    $lines = array_merge($lines, $this->blocks($content));
                }
            }
        }

        return $lines;
    }

    /**
     * @return array<int,string>
     */
    private function tableRows(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $tr) {
            if (!$tr instanceof DOMElement || $tr->localName !== 'tr') {
                continue;
            }

            $cells = [];
            foreach ($tr->childNodes as $tc) {
                if (!$tc instanceof DOMElement || $tc->localName !== 'tc') {
                    continue;
                }
                $cellText = implode(' ', $this->blocks($tc));
                $cells[] = trim((string) preg_replace('/\s+/u', ' ', $cellText));
            }

            $row = trim(implode(' | ', $cells), " |");
            if ($row !== '') {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function paragraphText(DOMElement $paragraph): string
    {
        $text = '';
        foreach ($paragraph->getElementsByTagNameNS(self::NS_W, '*') as $node) {
            switch ($node->localName) {
                case 't':
                    $text .= $node->textContent;
                    break;
                case 'tab':
                    $text .= "\t";
                    break;
                case 'br':
                case 'cr':
                    $text .= "\n";
                    break;
            }
        }

        return $text;
    }
}
