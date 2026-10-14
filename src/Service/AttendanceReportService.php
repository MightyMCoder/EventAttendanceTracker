<?php
/**
 * Contains the application service that prepares attendance report data.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Language;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;

/**
 * Prepares attendance report data independently of HTML, PDF and spreadsheet output.
 */
final class AttendanceReportService
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        private readonly Database $database,
        private readonly ProfileFields $profileFields,
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
    ): array
    {
        $rows = (new AttendanceRepository($this->database))->findSummary(
            $trackerId,
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

    /** @param array<int,array<string,mixed>> $rows
     *  @return array<int,array<string,mixed>>
     */
    private function prepareRows(array $rows): array
    {
        $decimalPlaces = min(4, max(0, (int) ($this->settings['event_attendance_rate_decimal_places'] ?? 0)));
        $redThreshold = min(100, max(0, (int) ($this->settings['event_attendance_rate_red_threshold'] ?? 50)));
        $orangeThreshold = min(100, max($redThreshold, (int) ($this->settings['event_attendance_rate_orange_threshold'] ?? 70)));
        $decimalSeparator = $this->language->getLanguageIsoCode() === 'de' ? ',' : '.';

        foreach ($rows as &$row) {
            // A report always has a defined value, including before the first event is completed.
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

    public function getDecimalPlaces(): int
    {
        return min(4, max(0, (int) ($this->settings['event_attendance_rate_decimal_places'] ?? 0)));
    }
}
