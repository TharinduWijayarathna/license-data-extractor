<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * One cleaned vendor licence row — the seven master workbook columns.
 */
final class LicenceRecord
{
    public const COLUMNS = [
        'VLFileNumber',
        'VLNumber',
        'CompanyName',
        'Address1',
        'ExpireDate',
        'LicenceType',
        'District',
    ];

    /** @var string */
    public $vlFileNumber;

    /** @var string */
    public $vlNumber;

    /** @var string */
    public $companyName;

    /** @var string Multi-premises addresses are separated by "\n" */
    public $address;

    /** @var DateTimeImmutable|null */
    public $expireDate;

    /** @var string */
    public $licenceType;

    /** @var string */
    public $district;

    public function __construct(
        string $vlFileNumber,
        string $vlNumber,
        string $companyName,
        string $address,
        ?DateTimeImmutable $expireDate,
        string $licenceType,
        string $district
    ) {
        $this->vlFileNumber = $vlFileNumber;
        $this->vlNumber = $vlNumber;
        $this->companyName = $companyName;
        $this->address = $address;
        $this->expireDate = $expireDate;
        $this->licenceType = $licenceType;
        $this->district = $district;
    }

    /**
     * Primary key used for deduplication (VLFileNumber + VLNumber).
     */
    public function key(): string
    {
        return self::makeKey($this->vlFileNumber, $this->vlNumber);
    }

    public static function makeKey(string $vlFileNumber, string $vlNumber): string
    {
        return strtoupper(trim($vlFileNumber)) . '||' . strtoupper(trim($vlNumber));
    }
}
