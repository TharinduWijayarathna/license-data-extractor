<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Applies the extraction business rules deterministically to the raw
 * fields returned by the AI extractor, so formatting never depends on the model.
 *
 * Raw input keys (see GeminiLicenceExtractor::RESPONSE_SCHEMA):
 *   VLFileNumber, VLNumber, CompanyName, Addresses[], EffectiveDate,
 *   ExpireDate, Wired, Wireless, Exclusions[], Districts[]
 */
final class LicenceNormalizer
{
    /** Exclusion shortcodes in their canonical output order. */
    public const EXCLUSION_CODES = ['CP', 'SAT', 'GPSVT', 'RCT'];

    /** The 25 administrative districts of Sri Lanka. */
    public const DISTRICTS = [
        'AMPARA', 'ANURADHAPURA', 'BADULLA', 'BATTICALOA', 'COLOMBO',
        'GALLE', 'GAMPAHA', 'HAMBANTOTA', 'JAFFNA', 'KALUTARA',
        'KANDY', 'KEGALLE', 'KILINOCHCHI', 'KURUNEGALA', 'MANNAR',
        'MATALE', 'MATARA', 'MONARAGALA', 'MULLAITIVU', 'NUWARA ELIYA',
        'POLONNARUWA', 'PUTTALAM', 'RATNAPURA', 'TRINCOMALEE', 'VAVUNIYA',
    ];

    private const DATE_FORMATS = ['!d/m/Y', '!d.m.Y', '!d-m-Y', '!Y-m-d', '!j/n/Y'];

    /** @var array<int,string> */
    private $warnings = [];

    /**
     * @param array<string,mixed> $raw
     */
    public function normalize(array $raw): LicenceRecord
    {
        $this->warnings = [];

        $fileNumber = self::cleanFileNumber(self::str($raw['VLFileNumber'] ?? ''));
        $vlNumber = self::cleanVlNumber(self::str($raw['VLNumber'] ?? ''));

        $effective = self::parseDate(self::str($raw['EffectiveDate'] ?? ''));
        if ($effective !== null) {
            $expire = self::expiryFromEffective($effective);
        } else {
            // Fall back to an expiry printed on the document, if any
            $expire = self::parseDate(self::str($raw['ExpireDate'] ?? ''));
            $this->warnings[] = $expire === null
                ? 'No effective or expiry date found'
                : 'No effective date found; used printed expiry date';
        }

        return new LicenceRecord(
            $fileNumber,
            $vlNumber,
            self::cleanCompanyName(self::str($raw['CompanyName'] ?? '')),
            self::cleanAddresses(self::strList($raw['Addresses'] ?? [])),
            $expire,
            self::buildLicenceType(
                !empty($raw['Wired']),
                !empty($raw['Wireless']),
                self::strList($raw['Exclusions'] ?? [])
            ),
            $this->buildDistrict(self::strList($raw['Districts'] ?? []))
        );
    }

    /**
     * Warnings raised by the last normalize() call.
     *
     * @return array<int,string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Rule 1: trim, drop non-printing characters and spaces around slashes.
     */
    public static function cleanFileNumber(string $value): string
    {
        $value = self::cleanText($value);
        $value = (string) preg_replace('/\s*\/\s*/', '/', $value);

        return strtoupper($value);
    }

    /**
     * Rule 2: strip leading/trailing punctuation noise ("..0470/26/R" → "0470/26/R").
     */
    public static function cleanVlNumber(string $value): string
    {
        $value = (string) preg_replace('/\s+/', '', self::cleanText($value));
        $value = (string) preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/', '', $value);

        return strtoupper($value);
    }

    /**
     * Rule 3: keep legal-entity brackets such as (PVT), but drop a pair of
     * parentheses that wraps the whole name.
     */
    public static function cleanCompanyName(string $value): string
    {
        $value = self::cleanText($value);

        while (self::isFullyWrapped($value)) {
            $value = trim(substr($value, 1, -1));
        }

        return $value;
    }

    /**
     * Rule 4: ASCII "NO." instead of the Numero sign, every premises ends with
     * a period, one address per line.
     *
     * @param array<int,string> $addresses
     */
    public static function cleanAddresses(array $addresses): string
    {
        $lines = [];
        foreach ($addresses as $address) {
            $address = (string) preg_replace('/№\s*\.?\s*/u', 'NO. ', $address);
            $address = self::cleanText($address);
            $address = rtrim($address, " ,;:");
            if ($address === '') {
                continue;
            }
            if (substr($address, -1) !== '.') {
                $address .= '.';
            }
            $lines[] = $address;
        }

        return implode("\n", $lines);
    }

    /**
     * Rule 5: Excel's DATE(YEAR(d)+1, MONTH(d), DAY(d)) - 1.
     */
    public static function expiryFromEffective(DateTimeImmutable $effective): DateTimeImmutable
    {
        $year = (int) $effective->format('Y') + 1;
        $month = (int) $effective->format('n');
        $day = (int) $effective->format('j');

        // setDate rolls 29 Feb into 1 Mar exactly like Excel's DATE()
        return $effective->setDate($year, $month, $day)->modify('-1 day');
    }

    public static function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
            if ($date !== false && $clean) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Rule 6: "WD", "WL" or "WD,WL", plus " excluding CP,SAT,..." when present.
     *
     * @param array<int,string> $exclusions
     */
    public static function buildLicenceType(bool $wired, bool $wireless, array $exclusions): string
    {
        $types = [];
        if ($wired) {
            $types[] = 'WD';
        }
        if ($wireless) {
            $types[] = 'WL';
        }

        $requested = array_map(function (string $code): string {
            return strtoupper(trim($code));
        }, $exclusions);
        $codes = array_values(array_intersect(self::EXCLUSION_CODES, $requested));

        $type = implode(',', $types);
        if ($codes !== []) {
            $type = trim($type . ' excluding ' . implode(',', $codes));
        }

        return $type;
    }

    /**
     * Rule 7: block capitals, deduplicated, sorted, ", " separated.
     *
     * @param array<int,string> $districts
     */
    private function buildDistrict(array $districts): string
    {
        $clean = [];
        foreach ($districts as $district) {
            $district = strtoupper(self::cleanText($district));
            $district = trim((string) preg_replace('/\s+DISTRICT$/', '', $district));
            if ($district === '') {
                continue;
            }
            if (!in_array($district, self::DISTRICTS, true)) {
                $this->warnings[] = "Unknown district '{$district}'";
            }
            $clean[$district] = true;
        }

        $names = array_keys($clean);
        sort($names, SORT_STRING);

        return implode(', ', $names);
    }

    /**
     * Replace control / zero-width characters with spaces and collapse whitespace.
     */
    private static function cleanText(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private static function isFullyWrapped(string $value): bool
    {
        $length = strlen($value);
        if ($length < 2 || $value[0] !== '(' || $value[$length - 1] !== ')') {
            return false;
        }

        $depth = 0;
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '(') {
                $depth++;
            } elseif ($value[$i] === ')') {
                $depth--;
                // Opening bracket closed before the end → not a wrapping pair
                if ($depth === 0 && $i < $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    /**
     * @param mixed $value
     */
    private static function str($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function strList($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
