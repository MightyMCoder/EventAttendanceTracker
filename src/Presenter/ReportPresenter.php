<?php
/**
 * Contains the presenter that prepares attendance report rows for every view.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Language;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportService;

/** Adds localized display values and colors to raw attendance report data. */
final class ReportPresenter
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        private readonly AttendanceReportService $reportService,
        private readonly array $settings,
        private readonly Language $language
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function getRows(
        int $trackerId,
        string $percentageOperator = 'all',
        float $percentageValue = 0.0,
        ?string $dateFrom = null,
        ?string $dateUntil = null
    ): array {
        return $this->prepareRows($this->reportService->getRows(
            $trackerId,
            $percentageOperator,
            $percentageValue,
            $dateFrom,
            $dateUntil
        ));
    }

    public function getDecimalPlaces(): int
    {
        return min(4, max(0, (int) ($this->settings['event_attendance_rate_decimal_places'] ?? 0)));
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
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
}
