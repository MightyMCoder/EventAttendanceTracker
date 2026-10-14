<?php
/**
 * Contains the application service that retrieves attendance report data.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use AdmidioPlugin\EventAttendance\ValueObject\AttendanceData;

/**
 * Retrieves raw attendance report data independently of display and export formats.
 */
final class AttendanceReportService
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        private readonly Database $database,
        private readonly ProfileFields $profileFields,
        private readonly array $settings
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
        $rows = (new AttendanceData($this->database))->findSummary(
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

        return $rows;
    }
}
