<?php
/**
 * Contains the presenter that prepares attendance report export data and views.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\Repository\TrackerRepository;
use DateTimeImmutable;

/** Prepares format-independent export rows and the Smarty view for PDF exports. */
final class ExportPresenter
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        private readonly Database $database,
        private readonly LegacyPluginContext $plugin,
        private readonly array $settings,
        private readonly ReportPresenter $reportPresenter
    ) {
    }

    /**
     * Loads all report rows to be exported.
     *
     * @param array<string,mixed> $tracker
     * @return array<int,array<string,mixed>>
     */
    public function getRows(
        array $tracker,
        string $percentageOperator,
        float $percentageValue,
        ?string $dateFrom,
        ?string $dateUntil
    ): array {
        return $this->reportPresenter->getRows(
            (int) $tracker['eat_id'],
            $percentageOperator,
            $percentageValue,
            $dateFrom,
            $dateUntil
        );
    }

    /** @return array<int,string> */
    public function createHeaders(): array
    {
        global $gL10n;

        return array($gL10n->get('SYS_NAME'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXPECTED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PRESENT'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ABSENT'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNKNOWN'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_RATE'));
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<int,string|int|float>> */
    public function createSpreadsheetRows(array $rows): array
    {
        return array_map(fn (array $row): array => array(trim($row['first_name'] . ' ' . $row['last_name']), $row['expected_count'], $row['present_count'], $row['excused_count'], $row['absent_count'], $row['unknown_count'], $row['cancelled_count'], round((float) $row['attendance_percentage'], $this->reportPresenter->getDecimalPlaces())), $rows);
    }

    /** @param array<string,mixed> $tracker @param array<int,array<string,mixed>> $rows @return array{html:string,logoPath:string} */
    public function createPdfView(array $tracker, array $rows, ?string $dateFrom, ?string $dateUntil): array
    {
        global $gL10n, $gSettingsManager;

        $page = EventAttendancePagePresenter::createPdfReport($this->plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name']);
        $occurrences = (new TrackerRepository($this->database))->findOccurrences((int) $tracker['eat_id'], 'all', $dateFrom, $dateUntil);
        $starts = array_map(static fn (array $entry): string => (string) $entry['dat_begin'], $occurrences);
        $ends = array_map(static fn (array $entry): string => (string) ($entry['dat_end'] ?: $entry['dat_begin']), $occurrences);
        $start = $starts === array() ? '' : (new DateTimeImmutable(min($starts)))->format($gSettingsManager->getString('system_date'));
        $end = $ends === array() ? '' : (new DateTimeImmutable(max($ends)))->format($gSettingsManager->getString('system_date'));
        $page->assignSmartyVariable('rows', $rows);
        $page->assignSmartyVariable('reportTitle', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PDF_TITLE'));
        $page->assignSmartyVariable('reportPeriod', $starts === array() ? '' : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PDF_PERIOD', array($start, $end)));
        $logoPath = $this->getPdfLogoPath();
        $page->assignSmartyVariable('hasLogo', $logoPath !== '');

        return array('html' => $page->getSmartyTemplate()->fetch('plugin.event-attendance.report.pdf.tpl'), 'logoPath' => $logoPath);
    }

    private function getPdfLogoPath(): string
    {
        $logoFile = trim((string) ($this->settings['event_attendance_pdf_logo_file'] ?? ''));
        $logoPath = $logoFile === '' ? false : realpath(ADMIDIO_PATH . '/' . ltrim($logoFile, '/'));
        $directories = array_filter(array(realpath(ADMIDIO_PATH . '/' . trim(FOLDER_DATA, '/')), realpath(ADMIDIO_PATH . '/themes')));
        $allowed = is_string($logoPath) && is_file($logoPath) && preg_match('/\.(?:png|jpe?g|gif)$/i', $logoPath) === 1 && array_reduce($directories, static fn(bool $result, string $directory): bool => $result || str_starts_with($logoPath, $directory . '/'), false);

        return $allowed ? $logoPath : '';
    }
}
