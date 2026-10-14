<?php
/**
 * Contains the application service that records and restores attendance data.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use DateTimeImmutable;

/** Coordinates writes to attendance records and occurrence cancellations. */
final class AttendanceActionService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function cancel(int $trackerId, string $eventUuid, int $userId): void
    {
        (new CancellationService($this->database))->cancel($trackerId, $eventUuid, $userId);
    }

    public function restore(int $trackerId, string $eventUuid): void
    {
        (new CancellationService($this->database))->restore($trackerId, $eventUuid);
    }

    /** @param array<string,mixed> $formValues */
    public function save(int $trackerId, string $eventUuid, int $userId, array $formValues, mixed $profileFields): void
    {
        // A manual recording needs its own expected-attendee snapshot, even when automation is disabled.
        (new AttendanceSnapshotService($this->database))->snapshotDueEventsForTracker($trackerId, new DateTimeImmutable(DATETIME_NOW));
        $roster = (new \AdmidioPlugin\EventAttendance\Repository\AttendanceRepository($this->database))
            ->findRoster($trackerId, $eventUuid, $profileFields);
        $statuses = array();
        foreach ($roster as $person) {
            $statuses[(int) $person['eau_usr_id']] = (string) ($formValues['attendance_status_' . (int) $person['eau_usr_id']] ?? '');
        }

        (new AttendanceService($this->database))->save($trackerId, $eventUuid, $userId, $statuses);
    }
}
