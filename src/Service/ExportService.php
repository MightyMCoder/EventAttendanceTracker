<?php
/**
 * Contains the application service that creates Event Attendance Tracker exports.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use AdmidioPlugin\EventAttendance\AttendanceReportPdfDocument;
use AdmidioPlugin\EventAttendance\EventAttendance;
use AdmidioPlugin\EventAttendance\Presenter\ExportPresenter;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Creates and sends attendance report exports. Mirrors the Inventory ExportService lifecycle:
 * build a temporary file first, then send it and remove it afterwards.
 */
final class ExportService
{
    /**
     * Create the export service with its data and view presenter.
     */
    public function __construct(private readonly ExportPresenter $presenter)
    {
    }

    /**
     * Create and send an export in the requested file format.
     *
     * File generation is kept separate from the response so errors can clean up
     * temporary files before any HTTP headers or download bytes are sent.
     *
     * @param array<string,mixed> $tracker
     * @return void
     */
    public function createExport(
        array $tracker,
        string $format,
        string $percentageOperator,
        float $percentageValue,
        string $orientation = 'L',
        ?string $dateFrom = null,
        ?string $dateUntil = null
    ): void
    {
        $export = $this->createExportFile($tracker, $format, $percentageOperator, $percentageValue, $orientation, $dateFrom, $dateUntil);

        $this->sendDownload($export, $format === 'pdf');
    }

    /**
     * Filename used by the browser download attribute for the PDF workaround on mobile clients.
     */
    public function getBrowserFilename(string $extension): string
    {
        return $this->presenter->getBrowserFilename($extension);
    }

    /**
     * Create a temporary export file without sending an HTTP response.
     *
     * This method is used by normal downloads and lets callers render the same
     * report data through a browser-specific download flow when necessary.
     *
     * @param array<string,mixed> $tracker
     * @return array{path:string,filename:string,contentType:string}
     * @throws Exception|\Smarty\Exception
     */
    public function createExportFile(
        array $tracker,
        string $format,
        string $percentageOperator,
        float $percentageValue,
        string $orientation = 'L',
        ?string $dateFrom = null,
        ?string $dateUntil = null
    ): array
    {
        if (!in_array($format, array('csv', 'xlsx', 'pdf'), true)) {
            throw new InvalidArgumentException('Invalid attendance report export format.');
        }
        if (!in_array($orientation, array('P', 'L'), true)) {
            throw new InvalidArgumentException('Invalid PDF orientation.');
        }

        $file = tempnam(ADMIDIO_PATH . FOLDER_TEMP_DATA, EventAttendance::PLUGIN_ID . '-export-');
        if ($file === false) {
            throw new RuntimeException('Could not create a temporary attendance report export file.');
        }
        $rows = $this->presenter->getRows($tracker, $percentageOperator, $percentageValue, $dateFrom, $dateUntil);

        try {
            return match ($format) {
                'csv' => $this->createCsvFile($file, $rows),
                'xlsx' => $this->createXlsxFile($file, $rows),
                'pdf' => $this->createPdfFile($file, $tracker, $rows, $orientation, $dateFrom, $dateUntil),
            };
        } catch (\Throwable $exception) {
            $this->deleteTemporaryFile($file);
            throw $exception;
        }
    }

    /**
     * Write the report rows as a UTF-8 CSV file.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{path:string,filename:string,contentType:string}
     */
    private function createCsvFile(string $file, array $rows): array
    {
        $output = fopen($file, 'wb');
        if ($output === false) {
            throw new RuntimeException('Could not create attendance report CSV export.');
        }
        fputcsv($output, $this->presenter->createHeaders(), ',', '"', '');
        foreach ($this->presenter->createSpreadsheetRows($rows) as $row) {
            $row[0] = self::escapeSpreadsheetFormula((string) $row[0]);
            fputcsv($output, $row, ',', '"', '');
        }
        fclose($output);

        return array('path' => $file, 'filename' => $this->presenter->getDownloadFilename('csv'), 'contentType' => 'text/csv; charset=UTF-8');
    }

    /**
     * Write the report rows as an XLSX workbook.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{path:string,filename:string,contentType:string}
     */
    private function createXlsxFile(string $file, array $rows): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($this->presenter->createHeaders(), null, 'A1');
        $data = $this->presenter->createSpreadsheetRows($rows);
        $sheet->fromArray($data, null, 'A2');
        foreach ($data as $index => $row) {
            $sheet->setCellValueExplicit('A' . ($index + 2), self::escapeSpreadsheetFormula((string) $row[0]), DataType::TYPE_STRING);
        }
        (new Xlsx($spreadsheet))->save($file);

        return array('path' => $file, 'filename' => $this->presenter->getDownloadFilename('xlsx'), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * Render the report data as a PDF file.
     *
     * The HTML view is supplied by the presenter; TCPDF only owns the document
     * lifecycle and the validated logo placement.
     *
     * @param array<string,mixed>            $tracker
     * @param array<int,array<string,mixed>> $rows
     * @return array{path:string,filename:string,contentType:string}
     * @throws Exception|\Smarty\Exception
     */
    private function createPdfFile(
        string $file,
        array $tracker,
        array $rows,
        string $orientation,
        ?string $dateFrom,
        ?string $dateUntil
    ): array
    {
        $view = $this->presenter->createPdfView($tracker, $rows, $dateFrom, $dateUntil);
        $pdf = new AttendanceReportPdfDocument($orientation, $this->presenter->createPdfFooterText());
        $pdf->AddPage();
        $pdf->writeHTML($view['html'], true, false, true);
        // Render the logo last: TCPDF's HTML table renderer may otherwise paint
        // the empty logo column over the image.
        $this->writePdfLogo($pdf, $view['logoPath']);
        $pdf->Output($file, 'F');

        return array('path' => $file, 'filename' => $this->presenter->getDownloadFilename('pdf'), 'contentType' => 'application/pdf');
    }

    /**
     * Draw the validated organization logo with its original aspect ratio.
     */
    private function writePdfLogo(AttendanceReportPdfDocument $pdf, string $logoPath): void
    {
        if ($logoPath === '') {
            return;
        }

        try {
            // TCPDF's HTML image handling is unreliable for local files and alpha
            // channels. Its native image renderer preserves proportions reliably.
            $margins = $pdf->getMargins();
            $contentWidth = $pdf->getPageWidth() - $margins['left'] - $margins['right'];
            // The PDF header reserves 22 % width and 22 mm height for the logo.
            // Limit both dimensions: otherwise a square organisation logo would
            // become much taller than the header and cover the report period.
            $maximumLogoWidth = min(50.0, ($contentWidth * 0.22) - 4.0);
            $maximumLogoHeight = 18.0;
            $imageSize = getimagesize($logoPath);
            $aspectRatio = is_array($imageSize) && $imageSize[1] > 0
                ? $imageSize[0] / $imageSize[1]
                : 1.0;
            $logoWidth = min($maximumLogoWidth, $maximumLogoHeight * $aspectRatio);
            $logoHeight = $logoWidth / $aspectRatio;
            $pdf->Image($logoPath, 12, 12, $logoWidth, $logoHeight, '', '', '', false, 300);
        } catch (\Throwable) {
            // A malformed image must not prevent the report itself from exporting.
        }
    }

    /**
     * Remove a temporary export file without masking the original failure.
     */
    private function deleteTemporaryFile(string $file): void
    {
        try {
            FileSystemUtils::deleteFileIfExists($file);
        } catch (RuntimeException) {
            // A stale temporary export is harmless and is cleaned up by Admidio's normal maintenance.
        }
    }

    /**
     * Stream a completed temporary export to the browser and remove it afterwards.
     *
     * @param array{path:string,filename:string,contentType:string} $export
     * @return void
     */
    private function sendDownload(array $export, bool $genericPdfDownload): void
    {
        header('Content-Type: ' . $export['contentType']);
        // Mobile browsers reliably download PDF output only without a server-side UTF-8 filename.
        header($genericPdfDownload
            ? 'Content-Disposition: attachment'
            : 'Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private');
        header('Pragma: public');
        header('Content-Length: ' . filesize($export['path']));
        if (ob_get_length() > 0) {
            ob_end_clean();
        }

        try {
            readfile($export['path']);
        } finally {
            $this->deleteTemporaryFile($export['path']);
        }
    }

    /**
     * Prevent formulas in spreadsheet cells that contain user-controlled text.
     */
    private static function escapeSpreadsheetFormula(string $value): string
    {
        if ($value !== '' && in_array($value[0], array('=', '+', '-', '@'), true)) {
            return "'" . $value;
        }

        return $value;
    }
}
