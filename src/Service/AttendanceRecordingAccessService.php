<?php
/**
 * Contains the access checks for attendance recording operations.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Exception;
use AdmidioPlugin\EventAttendance\ValueObject\TrackerData;
use DateTimeImmutable;

/** Applies the domain access rules for recording attendance for an occurrence. */
final class AttendanceRecordingAccessService
{
    public function __construct(private readonly TrackerData $trackers)
    {
    }

    /** @param array<string,mixed> $tracker */
    public function assertTrackerIsActive(array $tracker): void
    {
        if (!(bool) $tracker['eat_active']) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
    }

    /** @param array<string,mixed> $tracker */
    public function assertRecordingIsOpen(array $tracker, string $eventUuid, int $recordingLockDays): void
    {
        if ($recordingLockDays === 0) {
            return;
        }

        $occurrences = $this->trackers->findOccurrences((int) $tracker['eat_id'], 'all');
        $occurrence = array_values(array_filter(
            $occurrences,
            static fn(array $entry): bool => $entry['dat_uuid'] === $eventUuid
        ))[0] ?? false;
        if ($occurrence === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $eventEnd = new DateTimeImmutable($occurrence['dat_end'] ?: $occurrence['dat_begin']);
        if ($eventEnd->modify('+' . $recordingLockDays . ' days') <= new DateTimeImmutable(DATETIME_NOW)) {
            throw new Exception('PLG_EVENT_ATTENDANCE_TRACKER_RECORDING_LOCKED');
        }
    }
}
