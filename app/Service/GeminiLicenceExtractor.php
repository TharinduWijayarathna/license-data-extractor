<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Sends a licence document to the Gemini API and returns the raw records.
 *
 * PDFs (including scans) go up as inline data; .docx files are converted to
 * text first because Gemini does not read Word files directly. Formatting
 * rules are applied afterwards by LicenceNormalizer, so the model only has to
 * read facts off the page.
 */
class GeminiLicenceExtractor implements LicenceExtractor
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const MAX_ATTEMPTS = 3;

    public const SYSTEM_PROMPT = <<<'PROMPT'
You are an ETL engine extracting Sri Lankan telecommunication vendor licence details.
A document may contain one or many licences (one per page or section): return one object per licence, scanning every page.
Copy values exactly as printed; do not add citations, footnotes or commentary.

Licences are usually typed onto a pre-printed form, so field labels are often missing. The typical order is:
  1. Licence number and file number on the first line (e.g. "0881/26/R    LCS/VEN/4315/2024")
  2. Licensee (company) name
  3. Effective date (e.g. "30.09.2026")
  4. Licensed activities, e.g. "SALE, ... OF WIRED AND WIRELESS COMMUNICATION APPARATUS ... EXCLUDING SATELLITE RECEIVERS, ..."
  5. One line per licensed premises address
  6. Issue / signature date at the end; this is NOT the effective date

- VLFileNumber: the file reference starting with LCS/ (e.g. LCS/VEN/4315/2024). May be labelled "FILE NO".
- VLNumber: the licence number shaped like 0881/26/R (may be labelled "LICENCE NO").
- CompanyName: the licensee name. Keep legal-entity brackets such as (PVT) or (PRIVATE).
- Addresses: one entry per licensed premises, in document order. Keep original numbering like "1." or "2.".
  Write "NO." with plain ASCII letters, never the Numero sign.
- EffectiveDate: the licence effective date (item 3 above, or labelled "EFFECTIVE DATE") as DD/MM/YYYY. Empty string if absent.
- ExpireDate: an expiry date only if the document prints one, as DD/MM/YYYY; otherwise empty string.
- Wired / Wireless: true if the licence covers wired (WD) / wireless (WL) telecommunication apparatus.
- Exclusions: codes for apparatus explicitly excluded: CP (cellular phones), SAT (satellite receivers),
  GPSVT (GPS vehicle tracking), RCT (remote control toys). Empty array if none.
- Districts: the Sri Lankan administrative district of each premises (e.g. Beliatta -> HAMBANTOTA,
  Pokunuwita -> KALUTARA, Nittambuwa -> GAMPAHA), in block capitals.
PROMPT;

    /** @var array<string,mixed> Gemini responseSchema (OpenAPI subset) */
    public const RESPONSE_SCHEMA = [
        'type' => 'ARRAY',
        'items' => [
            'type' => 'OBJECT',
            'properties' => [
                'VLFileNumber' => ['type' => 'STRING'],
                'VLNumber' => ['type' => 'STRING'],
                'CompanyName' => ['type' => 'STRING'],
                'Addresses' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'EffectiveDate' => ['type' => 'STRING'],
                'ExpireDate' => ['type' => 'STRING'],
                'Wired' => ['type' => 'BOOLEAN'],
                'Wireless' => ['type' => 'BOOLEAN'],
                'Exclusions' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'STRING', 'enum' => LicenceNormalizer::EXCLUSION_CODES],
                ],
                'Districts' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => [
                'VLFileNumber', 'VLNumber', 'CompanyName', 'Addresses', 'EffectiveDate',
                'ExpireDate', 'Wired', 'Wireless', 'Exclusions', 'Districts',
            ],
        ],
    ];

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var int */
    private $timeout;

    /** @var DocxTextExtractor */
    private $docx;

    /** @var string Gemini 3+ thinking level ("minimal", "low", "medium", "high"); '' = model default */
    private $thinkingLevel;

    public function __construct(
        string $apiKey,
        string $model,
        int $timeout,
        DocxTextExtractor $docx,
        string $thinkingLevel = 'low'
    ) {
        if (trim($apiKey) === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set (see .env).');
        }

        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
        $this->docx = $docx;
        $this->thinkingLevel = $thinkingLevel;
    }

    public function extract(string $path): array
    {
        $payload = $this->buildPayload($path);
        $url = sprintf(self::ENDPOINT, rawurlencode($this->model));

        return self::parseResponse($this->send($url, $payload));
    }

    /**
     * @return array<string,mixed>
     */
    public function buildPayload(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Cannot read ' . basename($path));
            }
            $document = ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($bytes)]];
        } elseif ($extension === 'docx') {
            $text = $this->docx->extract($path);
            if ($text === '') {
                throw new RuntimeException('No text found in ' . basename($path));
            }
            $document = ['text' => "Document text:\n" . $text];
        } else {
            throw new RuntimeException("Unsupported file type .{$extension}");
        }

        // Gemini 3 models are tuned for the default temperature (1.0); lowering it can cause
        // looping. Output stays consistent because LicenceNormalizer does the formatting.
        $generationConfig = [
            'responseMimeType' => 'application/json',
            'responseSchema' => self::RESPONSE_SCHEMA,
        ];
        // thinkingLevel is not supported by Gemini 1.x / 2.x models
        if ($this->thinkingLevel !== '' && !preg_match('/^gemini-[12]\./', $this->model)) {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => $this->thinkingLevel];
        }

        return [
            'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    $document,
                    ['text' => 'Extract every licence record from this document.'],
                ],
            ]],
            'generationConfig' => $generationConfig,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function parseResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Gemini returned invalid JSON.');
        }

        $candidate = $decoded['candidates'][0] ?? null;
        if (!is_array($candidate)) {
            $reason = $decoded['promptFeedback']['blockReason'] ?? 'no candidates';
            throw new RuntimeException('Gemini returned no result (' . (is_string($reason) ? $reason : 'unknown') . ').');
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        $records = json_decode($text, true);
        if (!is_array($records)) {
            $finish = $candidate['finishReason'] ?? 'unknown';
            throw new RuntimeException('Could not parse Gemini output (finishReason: ' . (is_string($finish) ? $finish : 'unknown') . ').');
        }

        // A single object instead of an array
        if ($records !== [] && array_keys($records) !== range(0, count($records) - 1)) {
            $records = [$records];
        }

        return array_values(array_filter($records, 'is_array'));
    }

    /**
     * POST JSON with retries on rate limits (429) and server errors.
     *
     * @param array<string,mixed> $payload
     */
    protected function send(string $url, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        for ($attempt = 1; ; $attempt++) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Could not initialise cURL.');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey,
                ],
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if (is_string($body) && $status >= 200 && $status < 300) {
                return $body;
            }

            $retryable = $body === false || $status === 429 || $status >= 500;
            if (!$retryable || $attempt >= self::MAX_ATTEMPTS) {
                $detail = is_string($body) ? self::errorMessage($body) : $error;
                throw new RuntimeException("Gemini API request failed (HTTP {$status}): {$detail}");
            }

            // Free tier is rate limited per minute: back off 10s, 20s, ...
            sleep(10 * $attempt);
        }
    }

    private static function errorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

        return is_string($message) ? $message : substr($body, 0, 300);
    }
}
