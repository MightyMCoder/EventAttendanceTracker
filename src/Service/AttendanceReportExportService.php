<?php

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\AttendanceReportPdfDocument;
use AdmidioPlugin\EventAttendance\EventAttendance;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\Presenter\ExportDownloadResponder;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Creates and sends attendance report exports. Mirrors the Inventory ExportService lifecycle:
 * build a temporary file first, then send it and remove it afterwards.
 */
final class AttendanceReportExportService
{
    private readonly ExportDownloadResponder $downloadResponder;

    /** @param array<string,mixed> $settings */
    public function __construct(
        private readonly Database $database,
        private readonly LegacyPluginContext $plugin,
        private readonly AttendanceReportService $reportService,
        private readonly array $settings,
        ?ExportDownloadResponder $downloadResponder = null
    ) {
        $this->downloadResponder = $downloadResponder ?? new ExportDownloadResponder();
    }

    /** @param array<string,mixed> $tracker */
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

        $this->downloadResponder->send($export, $format === 'pdf');
    }

    /** Filename used by the browser download attribute for the PDF workaround on mobile clients. */
    public function getBrowserFilename(string $extension): string
    {
        $configured = trim((string) ($this->settings['event_attendance_export_filename'] ?? ''));

        return Language::translateIfTranslationStrId(
            $configured !== '' ? $configured : EventAttendance::PLUGIN_NAME_TRANSLATION_ID
        ) . '.' . $extension;
    }

    /**
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
        $rows = $this->reportService->getRows((int) $tracker['eat_id'], $percentageOperator, $percentageValue, $dateFrom, $dateUntil);

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

    /** @param array<int,array<string,mixed>> $rows
     *  @return array{path:string,filename:string,contentType:string}
     */
    private function createCsvFile(string $file, array $rows): array
    {
        $output = fopen($file, 'wb');
        if ($output === false) {
            throw new RuntimeException('Could not create attendance report CSV export.');
        }
        fputcsv($output, $this->headers(), ',', '"', '');
        foreach ($this->spreadsheetRows($rows) as $row) {
            $row[0] = SpreadsheetValueEscaper::escapeFormula((string) $row[0]);
            fputcsv($output, $row, ',', '"', '');
        }
        fclose($output);

        return array('path' => $file, 'filename' => $this->filename('csv'), 'contentType' => 'text/csv; charset=UTF-8');
    }

    /** @param array<int,array<string,mixed>> $rows
     *  @return array{path:string,filename:string,contentType:string}
     */
    private function createXlsxFile(string $file, array $rows): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($this->headers(), null, 'A1');
        $data = $this->spreadsheetRows($rows);
        $sheet->fromArray($data, null, 'A2');
        foreach ($data as $index => $row) {
            $sheet->setCellValueExplicit('A' . ($index + 2), SpreadsheetValueEscaper::escapeFormula((string) $row[0]), DataType::TYPE_STRING);
        }
        (new Xlsx($spreadsheet))->save($file);

        return array('path' => $file, 'filename' => $this->filename('xlsx'), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** @param array<string,mixed> $tracker
     *  @param array<int,array<string,mixed>> $rows
     *  @return array{path:string,filename:string,contentType:string}
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
        global $gL10n, $gSettingsManager;

        $page = PagePresenter::withHtmlIDAndHeadline('adm_event_attendance_report_pdf', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name']);
        $page->addTemplateFolder($this->plugin->templatesDirectory());
        $occurrences = (new \AdmidioPlugin\EventAttendance\Repository\TrackerRepository($this->database))->findOccurrences((int) $tracker['eat_id'], 'all', $dateFrom, $dateUntil);
        $startDates = array_map(static fn(array $entry): string => (string) $entry['dat_begin'], $occurrences);
        $endDates = array_map(static fn(array $entry): string => (string) ($entry['dat_end'] ?: $entry['dat_begin']), $occurrences);
        $startDate = $startDates === array() ? '' : (new DateTimeImmutable(min($startDates)))->format($gSettingsManager->getString('system_date'));
        $endDate = $endDates === array() ? '' : (new DateTimeImmutable(max($endDates)))->format($gSettingsManager->getString('system_date'));
        $page->assignSmartyVariable('rows', $rows);
        $page->assignSmartyVariable('reportTitle', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PDF_TITLE'));
        $page->assignSmartyVariable('reportPeriod', $startDates === array() ? '' : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PDF_PERIOD', array($startDate, $endDate)));

        $pdf = new AttendanceReportPdfDocument(
            $orientation,
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PDF_CREATED_AT', array((new DateTimeImmutable(DATETIME_NOW))->format($gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time'))))
        );
        $logoPath = $this->assignPdfLogo($page);
        $pdf->AddPage();
        $pdf->writeHTML($page->getSmartyTemplate()->fetch('plugin.event-attendance.report.pdf.tpl'), true, false, true);
        // Render the logo last: TCPDF's HTML table renderer may otherwise paint
        // the empty logo column over the image.
        $this->writePdfLogo($pdf, $logoPath);
        $pdf->Output($file, 'F');

        return array('path' => $file, 'filename' => $this->filename('pdf'), 'contentType' => 'application/pdf');
    }

    /** @param array<int,array<string,mixed>> $rows
     *  @return array<int,array<int,string|int|float>>
     */
    private function spreadsheetRows(array $rows): array
    {
        return array_map(fn (array $row): array => array(
            trim($row['first_name'] . ' ' . $row['last_name']),
            $row['expected_count'], $row['present_count'], $row['excused_count'], $row['absent_count'],
            $row['unknown_count'], $row['cancelled_count'], round((float) $row['attendance_percentage'], $this->reportService->getDecimalPlaces())
        ), $rows);
    }

    /** @return array<int,string> */
    private function headers(): array
    {
        global $gL10n;
        return array($gL10n->get('SYS_NAME'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXPECTED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PRESENT'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ABSENT'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNKNOWN'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_RATE'));
    }

    private function filename(string $extension): string
    {
        $configured = trim((string) ($this->settings['event_attendance_export_filename'] ?? ''));
        $translated = Language::translateIfTranslationStrId($configured !== '' ? $configured : EventAttendance::PLUGIN_NAME_TRANSLATION_ID);
        $name = trim((string) preg_replace('/[\\x00-\\x1F\\x7F"\\\\\/:;]+/u', '_', $translated));
        $name = strtr($name, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'));
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: EventAttendance::PLUGIN_NAME_TRANSLATION_ID;
        $name = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '_', $name));
        $name = (string) preg_replace('/\s+/', '-', $name);
        return ($name === '' ? EventAttendance::PLUGIN_NAME_TRANSLATION_ID : $name) . '.' . $extension;
    }

    private function assignPdfLogo(PagePresenter $page): string
    {
        $logoFile = trim((string) ($this->settings['event_attendance_pdf_logo_file'] ?? ''));
        $logoPath = $logoFile === '' ? false : realpath(ADMIDIO_PATH . '/' . ltrim($logoFile, '/'));
        $directories = array_filter(array(realpath(ADMIDIO_PATH . '/' . trim(FOLDER_DATA, '/')), realpath(ADMIDIO_PATH . '/themes')));
        $allowed = is_string($logoPath) && is_file($logoPath) && preg_match('/\.(?:png|jpe?g|gif)$/i', $logoPath) === 1
            && array_reduce($directories, static fn(bool $result, string $directory): bool => $result || str_starts_with($logoPath, $directory . '/'), false);
        $page->assignSmartyVariable('hasLogo', $allowed);

        return $allowed ? $logoPath : '';
    }

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

    private function deleteTemporaryFile(string $file): void
    {
        try {
            FileSystemUtils::deleteFileIfExists($file);
        } catch (RuntimeException) {
            // A stale temporary export is harmless and is cleaned up by Admidio's normal maintenance.
        }
    }
}
