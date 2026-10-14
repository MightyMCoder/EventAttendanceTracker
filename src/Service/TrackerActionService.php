<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 */


namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;

/** Coordinates state-changing tracker actions submitted by the web controller. */
final class TrackerActionService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<string,mixed> $formValues */
    public function save(int $organizationId, int $userId, string $mode, array $formValues, ?string $trackerUuid = null): void
    {
        $calendarId = ($formValues['tracker_source'] ?? 'events') === 'calendar'
            ? (int) ($formValues['tracker_calendar'] ?? 0)
            : null;
        $arguments = array(
            $organizationId,
            $userId,
            (string) $formValues['tracker_name'],
            (string) $formValues['tracker_description'],
            $formValues['tracker_roles'] ?? array(),
            $formValues['tracker_events'] ?? array(),
            $calendarId
        );
        $trackers = new TrackerService($this->database);

        if ($mode === 'save') {
            $trackers->create(...$arguments);
            return;
        }
        if ($mode !== 'save_edit' || $trackerUuid === null) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        array_splice($arguments, 1, 0, $trackerUuid);
        $trackers->update(...$arguments);
    }

    public function setActive(int $organizationId, int $userId, string $trackerUuid, bool $active): void
    {
        $trackers = new TrackerService($this->database);
        if ($active) {
            $trackers->activate($organizationId, $trackerUuid, $userId);
            return;
        }

        $trackers->deactivate($organizationId, $trackerUuid, $userId);
    }

    public function delete(int $organizationId, string $trackerUuid): void
    {
        (new TrackerService($this->database))->delete($organizationId, $trackerUuid);
    }
}
