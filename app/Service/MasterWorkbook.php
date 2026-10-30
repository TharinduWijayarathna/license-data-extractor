<?php

declare(strict_types=1);

namespace App\Service;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * The master Excel workbook (one "VendorLicences" sheet, seven columns).
 *
 * Layout rules: ExpireDate (E) is a real date shown as DD/MM/YYYY and
 * right-aligned; Address1 (D) wraps; every other column is left-aligned text.
 */
final class MasterWorkbook
{
    public const SHEET = 'VendorLicences';

    private const DATE_FORMAT = 'dd/mm/yyyy';

    private const WIDTHS = ['A' => 22, 'B' => 14, 'C' => 40, 'D' => 50, 'E' => 13, 'F' => 30, 'G' => 24];

    /** @var string */
    private $path;

    /** @var Spreadsheet */
    private $spreadsheet;

    /** @var Worksheet */
    private $sheet;

    private function __construct(string $path, Spreadsheet $spreadsheet)
    {
        $sheet = $spreadsheet->getSheetByName(self::SHEET);
        if ($sheet === null) {
            throw new RuntimeException(basename($path) . ' has no "' . self::SHEET . '" sheet.');
        }

        $this->path = $path;
        $this->spreadsheet = $spreadsheet;
        $this->sheet = $sheet;
    }

    /**
     * Open the workbook, or start a new one with the header row.
     */
    public static function open(string $path): self
    {
        if (is_file($path)) {
            return new self($path, IOFactory::load($path));
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->fromArray(LicenceRecord::COLUMNS, null, 'A1');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (self::WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return new self($path, $spreadsheet);
    }

    /**
     * Existing primary keys (VLFileNumber + VLNumber).
     *
     * @return array<string,true>
     */
    public function keys(): array
    {
        $keys = [];
        $last = $this->sheet->getHighestDataRow();
        for ($row = 2; $row <= $last; $row++) {
            $file = trim((string) $this->sheet->getCell('A' . $row)->getValue());
            $number = trim((string) $this->sheet->getCell('B' . $row)->getValue());
            if ($file !== '' || $number !== '') {
                $keys[LicenceRecord::makeKey($file, $number)] = true;
            }
        }

        return $keys;
    }

    public function append(LicenceRecord $record): void
    {
        $row = $this->sheet->getHighestDataRow() + 1;
        $texts = [
            'A' => $record->vlFileNumber,
            'B' => $record->vlNumber,
            'C' => $record->companyName,
            'D' => $record->address,
            'F' => $record->licenceType,
            'G' => $record->district,
        ];

        // Explicit strings so Excel never reinterprets "0881/26/R" as a date
        foreach ($texts as $column => $value) {
            $this->sheet->getCell($column . $row)->setValueExplicit($value, DataType::TYPE_STRING);
        }

        if ($record->expireDate !== null) {
            $this->sheet->getCell('E' . $row)->setValue(ExcelDate::PHPToExcel($record->expireDate));
            $this->sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        }

        $this->sheet->getStyle("A{$row}:G{$row}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_TOP);
        $this->sheet->getStyle('D' . $row)->getAlignment()->setWrapText(true);
        $this->sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /**
     * Rows as display strings (dates formatted DD/MM/YYYY).
     *
     * @return array<int,array<string,string>>
     */
    public function rows(): array
    {
        $rows = [];
        $last = $this->sheet->getHighestDataRow();
        for ($row = 2; $row <= $last; $row++) {
            $values = [];
            foreach (LicenceRecord::COLUMNS as $i => $name) {
                $values[$name] = (string) $this->sheet->getCell([$i + 1, $row])->getFormattedValue();
            }
            if (implode('', $values) !== '') {
                $rows[] = $values;
            }
        }

        return $rows;
    }

    /**
     * Save via a temp file + rename so a crash never leaves a half-written master.
     */
    public function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }

        $tmp = $dir . DIRECTORY_SEPARATOR . '.~' . basename($this->path) . '.' . getmypid() . '.xlsx';
        IOFactory::createWriter($this->spreadsheet, 'Xlsx')->save($tmp);

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException(
                'Cannot replace ' . basename($this->path) . ' — is it open in Excel?'
            );
        }
    }
}
