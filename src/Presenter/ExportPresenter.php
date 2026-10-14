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
use Admidio\Infrastructure\Language;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use AdmidioPlugin\EventAttendance\EventAttendance;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\ValueObject\AttendanceData;
use AdmidioPlugin\EventAttendance\ValueObject\TrackerData;
use DateTimeImmutable;

/**
 * Prepares format-independent export rows and the Smarty view for PDF exports.
 */
final class ExportPresenter
{
    /**
     * Initialize report presentation with the shared dependencies.
     *
     * @param array<string,mixed> $settings
     * @return void
     */
    public function __construct(
        private readonly Database $database,
        private readonly TrackerData $trackers,
        private readonly LegacyPluginContext $plugin,
        private readonly ProfileFields $profileFields,
        private readonly array $settings,
        private readonly Language $language
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
        $rows = (new AttendanceData($this->database))->findSummary(
            (int) $tracker['eat_id'],
            $this->profileFields,
            (bool) ($this->settings['event_attendance_rate_counts_excused'] ?? false),
            $dateFrom,
            $dateUntil
        );
        if ($percentageOperator !== 'all') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($percentageOperator, $percentageValue): bool {
                $percentage = (float) ($row['attendance_percentage'] ?? 0.0);

                return $percentageOperator === 'lt'
                    ? $percentage < $percentageValue
                    : $percentage > $percentageValue;
            }));
        }

        return $this->prepareRows($rows);
    }

    /**
     * Return the configured number of decimal places for attendance rates.
     */
    public function getDecimalPlaces(): int
    {
        return min(4, max(0, (int) ($this->settings['event_attendance_rate_decimal_places'] ?? 0)));
    }

    /**
     * Create localized spreadsheet column headers.
     *
     * @return array<int,string>
     */
    public function createHeaders(): array
    {
        global $gL10n;

        return array($gL10n->get('SYS_NAME'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXPECTED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PRESENT'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ABSENT'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNKNOWN'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED'), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_RATE'));
    }

    /**
     * Filename for the browser download attribute.
     */
    public function getBrowserFilename(string $extension): string
    {
        $configured = trim((string) ($this->settings['event_attendance_export_filename'] ?? ''));

        return Language::translateIfTranslationStrId(
            $configured !== '' ? $configured : EventAttendance::PLUGIN_NAME_TRANSLATION_ID
        ) . '.' . $extension;
    }

    /**
     * Filename suitable for the HTTP Content-Disposition header.
     */
    public function getDownloadFilename(string $extension): string
    {
        $configured = trim((string) ($this->settings['event_attendance_export_filename'] ?? ''));
        $translated = Language::translateIfTranslationStrId(
            $configured !== '' ? $configured : EventAttendance::PLUGIN_NAME_TRANSLATION_ID
        );
        $name = trim((string) preg_replace('/[\\x00-\\x1F\\x7F"\\\\\/:;]+/u', '_', $translated));
        $name = strtr($name, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'));
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: EventAttendance::PLUGIN_NAME_TRANSLATION_ID;
        $name = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '_', $name));
        $name = (string) preg_replace('/\s+/', '-', $name);

        return ($name === '' ? EventAttendance::PLUGIN_NAME_TRANSLATION_ID : $name) . '.' . $extension;
    }

    /**
     * Create the localized creation timestamp displayed in a PDF footer.
     */
    public function createPdfFooterText(): string
    {
        global $gL10n, $gSettingsManager;

        return $gL10n->get(
            'PLG_EVENT_ATTENDANCE_TRACKER_PDF_CREATED_AT',
            array((new DateTimeImmutable(DATETIME_NOW))->format(
                $gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time')
            ))
        );
    }

    /**
     * Transform report rows into scalar spreadsheet cell values.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<int,string|int|float>>
     */
    public function createSpreadsheetRows(array $rows): array
    {
        return array_map(fn (array $row): array => array(trim($row['first_name'] . ' ' . $row['last_name']), $row['expected_count'], $row['present_count'], $row['excused_count'], $row['absent_count'], $row['unknown_count'], $row['cancelled_count'], round((float) $row['attendance_percentage'], $this->getDecimalPlaces())), $rows);
    }

    /**
     * Render the Smarty view and resolve the optional PDF logo.
     *
     * @param array<string,mixed> $tracker
     * @param array<int,array<string,mixed>> $rows
     * @return array{html:string,logoPath:string,title:string}
     */
    public function createPdfView(array $tracker, array $rows, ?string $dateFrom, ?string $dateUntil): array
    {
        global $gL10n, $gSettingsManager;

        $reportTitle = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name'];
        $page = EventAttendancePagePresenter::createPdfReport($this->plugin, $reportTitle);
        $occurrences = $this->trackers->findOccurrences((int) $tracker['eat_id'], 'all', $dateFrom, $dateUntil);
        $starts = array_map(static fn (array $entry): string => (string) $entry['dat_begin'], $occurrences);
        $ends = array_map(static fn (array $entry): string => (string) ($entry['dat_end'] ?: $entry['dat_begin']), $occurrences);
        $start = $starts === array() ? '' : (new DateTimeImmutable(min($starts)))->format($gSettingsManager->getString('system_date'));
        $end = $ends === array() ? '' : (new DateTimeImmutable(max($ends)))->format($gSettingsManager->getString('system_date'));
        $page->assignSmartyVariable('rows', $rows);
        $page->assignSmartyVariable('reportPeriod', $starts === array() ? '' : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PDF_PERIOD', array($start, $end)));
        $logoPath = $this->getPdfLogoPath();

        return array(
            'html' => $page->getSmartyTemplate()->fetch('plugin.event-attendance.report.pdf.tpl'),
            'logoPath' => $logoPath,
            'title' => $reportTitle
        );
    }

    /**
     * Enrich raw report aggregates with presentation-specific values.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function prepareRows(array $rows): array
    {
        $decimalPlaces = $this->getDecimalPlaces();
        $redThreshold = min(100, max(0, (int) ($this->settings['event_attendance_rate_red_threshold'] ?? 50)));
        $orangeThreshold = min(100, max($redThreshold, (int) ($this->settings['event_attendance_rate_orange_threshold'] ?? 70)));
        $decimalSeparator = $this->language->getLanguageIsoCode() === 'de' ? ',' : '.';

        foreach ($rows as &$row) {
            $percentage = min(100.0, max(0.0, (float) ($row['attendance_percentage'] ?? 0.0)));
            $row['attendance_percentage'] = $percentage;
            $row['attendance_percentage_display'] = number_format($percentage, $decimalPlaces, $decimalSeparator, '');
            $row['attendance_class'] = $percentage <= $redThreshold
                ? 'bad'
                : ($percentage <= $orangeThreshold ? 'warning' : 'good');
            $row['attendance_color'] = $row['attendance_class'] === 'good'
                ? '#00a000'
                : ($row['attendance_class'] === 'warning' ? '#e87800' : '#e00000');
        }
        unset($row);

        return $rows;
    }

    /**
     * Resolve and validate the configured PDF logo path.
     */
    private function getPdfLogoPath(): string
    {
        $logoFile = trim((string) ($this->settings['event_attendance_pdf_logo_file'] ?? ''));
        $logoPath = $logoFile === '' ? false : realpath(ADMIDIO_PATH . '/' . ltrim($logoFile, '/'));
        $directories = array_filter(array(realpath(ADMIDIO_PATH . '/' . trim(FOLDER_DATA, '/')), realpath(ADMIDIO_PATH . '/themes')));
        $allowed = is_string($logoPath) && is_file($logoPath) && preg_match('/\.(?:png|jpe?g|gif)$/i', $logoPath) === 1 && array_reduce($directories, static fn(bool $result, string $directory): bool => $result || str_starts_with($logoPath, $directory . '/'), false);

        return $allowed ? $logoPath : '';
    }
}
