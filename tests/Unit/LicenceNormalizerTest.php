<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\LicenceNormalizer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class LicenceNormalizerTest extends TestCase
{
    public function testNormalizesTheSampleRecordFromTheSpec(): void
    {
        $normalizer = new LicenceNormalizer();
        $record = $normalizer->normalize([
            'VLFileNumber' => ' LCS / VEN / 4315 / 2024 ',
            'VLNumber' => '..0881/26/R.',
            'CompanyName' => 'SAMARA COMMUNICATION AND PHONE SHOP',
            'Addresses' => ['№ 178, ANAGARIKA DHARMAPALA MAWATHA, MATARA', 'NO.48, TANGALLE ROAD, BELIATTA.'],
            'EffectiveDate' => '30/09/2026',
            'ExpireDate' => '',
            'Wired' => true,
            'Wireless' => true,
            'Exclusions' => ['GPSVT', 'SAT'],
            'Districts' => ['matara', 'Hambantota District', 'MATARA'],
        ]);

        $this->assertSame('LCS/VEN/4315/2024', $record->vlFileNumber);
        $this->assertSame('0881/26/R', $record->vlNumber);
        $this->assertSame(
            "NO. 178, ANAGARIKA DHARMAPALA MAWATHA, MATARA.\nNO.48, TANGALLE ROAD, BELIATTA.",
            $record->address
        );
        $this->assertNotNull($record->expireDate);
        $this->assertSame('29/09/2027', $record->expireDate->format('d/m/Y'));
        $this->assertSame('WD,WL excluding SAT,GPSVT', $record->licenceType);
        $this->assertSame('HAMBANTOTA, MATARA', $record->district);
        $this->assertSame([], $normalizer->warnings());
    }

    public function testExpiryMatchesExcelDateArithmeticForLeapDay(): void
    {
        $expiry = LicenceNormalizer::expiryFromEffective(new DateTimeImmutable('2024-02-29'));
        $this->assertSame('2025-02-28', $expiry->format('Y-m-d'));

        $expiry = LicenceNormalizer::expiryFromEffective(new DateTimeImmutable('2026-01-01'));
        $this->assertSame('2026-12-31', $expiry->format('Y-m-d'));
    }

    public function testCompanyNameKeepsEntityBracketsButDropsWrappingPair(): void
    {
        $this->assertSame('ABC MOBILE (PVT) LTD', LicenceNormalizer::cleanCompanyName('(ABC MOBILE (PVT) LTD)'));
        $this->assertSame('ABC MOBILE (PVT) LTD', LicenceNormalizer::cleanCompanyName('ABC MOBILE (PVT) LTD'));
        $this->assertSame('(PVT) X (LTD)', LicenceNormalizer::cleanCompanyName('(PVT) X (LTD)'));
    }

    public function testLicenceTypeVariants(): void
    {
        $this->assertSame('WD', LicenceNormalizer::buildLicenceType(true, false, []));
        $this->assertSame('WL', LicenceNormalizer::buildLicenceType(false, true, []));
        $this->assertSame('WD,WL excluding CP,RCT', LicenceNormalizer::buildLicenceType(true, true, ['rct', 'CP', 'BOGUS']));
    }

    public function testFallsBackToPrintedExpiryAndWarnsOnUnknownDistrict(): void
    {
        $normalizer = new LicenceNormalizer();
        $record = $normalizer->normalize([
            'VLFileNumber' => 'LCS/VEN/1/2026',
            'VLNumber' => '0001/26/N',
            'EffectiveDate' => 'unknown',
            'ExpireDate' => '04/10/2027',
            'Districts' => ['Atlantis'],
        ]);

        $this->assertNotNull($record->expireDate);
        $this->assertSame('04/10/2027', $record->expireDate->format('d/m/Y'));
        $this->assertSame('ATLANTIS', $record->district);
        $this->assertCount(2, $normalizer->warnings());
    }

    public function testParseDateRejectsInvalidDates(): void
    {
        $this->assertNull(LicenceNormalizer::parseDate('31/02/2026'));
        $this->assertNull(LicenceNormalizer::parseDate(''));
        $date = LicenceNormalizer::parseDate('2026-10-02');
        $this->assertNotNull($date);
        $this->assertSame('02/10/2026', $date->format('d/m/Y'));
    }
}
