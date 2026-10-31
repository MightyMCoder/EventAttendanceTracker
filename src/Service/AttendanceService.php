<?php
/**
 * Contains shared attendance domain operations.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\EventAttendance\ValueObject\AttendanceData;
use AdmidioPlugin\EventAttendance\ValueObject\TrackerData;
use DateTimeImmutable;

final class AttendanceService
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const EXPECTED = TABLE_PREFIX . '_event_attendance_expected_users';
    private const RECORDS = TABLE_PREFIX . '_event_attendance_records';
    private const TRACKERS = TABLE_PREFIX . '_event_attendance_trackers';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    private const TRACKER_ROLES = TABLE_PREFIX . '_event_attendance_tracker_roles';

    /**
     * Create the attendance service and optionally reuse tracker data.
     */
    public function __construct(
        private readonly Database $database,
        private readonly ?TrackerData $trackers = null
    )
    {
    }

    /**
     * Ensure that the selected tracker has not been deactivated.

     * @param array<string,mixed> $tracker
     */
    public function assertTrackerIsActive(array $tracker): void
    {
        if (!(bool) $tracker['eat_active']) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
    }

    /**
     * Ensure that the configured recording lock does not block this occurrence.

     * @param array<string,mixed> $tracker
     */
    public function assertRecordingIsOpen(array $tracker, string $eventUuid, int $recordingLockDays): void
    {
        if ($recordingLockDays === 0) {
            return;
        }

        $occurrences = ($this->trackers ?? new TrackerData($this->database))->findOccurrences((int) $tracker['eat_id'], 'all');
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

    /**
     * Save statuses submitted by the attendance-recording form.
     *
     * The roster is resolved server-side so submitted status fields cannot create
     * records for a member who is not expected at the selected occurrence.
     *
     * @param array<string,mixed> $formValues
     * @return void
     */
    public function saveForm(int $trackerId, string $eventUuid, int $userId, array $formValues, mixed $profileFields): void
    {
        (new AttendanceSnapshotService($this->database))->snapshotDueEventsForTracker($trackerId, new DateTimeImmutable(DATETIME_NOW));
        $roster = (new AttendanceData($this->database))->findRoster($trackerId, $eventUuid, $profileFields);
        $defaultExcuses = json_decode((string) ($formValues['attendance_default_excuses'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $changedUsers = json_decode((string) ($formValues['attendance_changed_users'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($defaultExcuses) || !is_array($changedUsers)) {
            throw new \InvalidArgumentException('Invalid attendance form state.');
        }
        $statuses = array();
        foreach ($roster as $person) {
            $personId = (int) $person['eau_usr_id'];
            if (!in_array($personId, $changedUsers, true)
                && (in_array($personId, $defaultExcuses, true) || ($person['ear_id'] === null && $person['ear_status'] === 'excused'))) {
                // An untouched default is a preview until event end, not a manual entry.
                if ($person['ear_id'] === null && $person['ear_status'] === 'excused' && $person['event_end'] <= DATETIME_NOW) {
                    $statuses[$personId] = 'excused';
                }
                continue;
            }
            $field = 'attendance_status_' . (int) $person['eau_usr_id'];
            if (array_key_exists($field, $formValues)) {
                $statuses[(int) $person['eau_usr_id']] = (string) $formValues[$field];
            }
        }
        $this->save($trackerId, $eventUuid, $userId, $statuses);
    }

    /**
     * Mark one configured occurrence as cancelled while preserving its snapshot.
     */
    public function cancel(int $trackerId, string $eventUuid, int $actorId): void
    {
        $event = $this->findConfiguredEvent($trackerId, $eventUuid);
        $this->database->startTransaction();
        try {
            (new AttendanceSnapshotService($this->database))->snapshotEventWithinTransaction($trackerId, (int) $event['dat_id'], new DateTimeImmutable(DATETIME_NOW));
            $row = $this->database->queryPrepared('SELECT eae_id FROM ' . self::EVENTS . ' WHERE eae_tracker_id = ? AND eae_dat_id = ?', [$trackerId, (int) $event['dat_id']])->fetch();
            if ($row === false) {
                throw new \LogicException('Could not create the tracker event snapshot.');
            }
            $this->database->queryPrepared('UPDATE ' . self::EVENTS . ' SET eae_cancelled = true, eae_cancelled_by_usr_id = ?, eae_cancelled_at = CURRENT_TIMESTAMP WHERE eae_id = ?', [$actorId, (int) $row['eae_id']]);
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /**
     * Restore a previously cancelled occurrence.
     */
    public function restore(int $trackerId, string $eventUuid): void
    {
        $event = $this->findConfiguredEvent($trackerId, $eventUuid);
        $restored = $this->database->queryPrepared('UPDATE ' . self::EVENTS . ' SET eae_cancelled = false, eae_cancelled_by_usr_id = NULL, eae_cancelled_at = NULL WHERE eae_tracker_id = ? AND eae_dat_id = ? AND eae_cancelled = true', [$trackerId, (int) $event['dat_id']])->rowCount();
        if ($restored !== 1) {
            throw new \InvalidArgumentException('Event is not cancelled for this tracker.');
        }
    }
    /**
     * Persist all attendance states for a single tracker occurrence.
     *
     * @param array<int,string> $statuses
     * @return void
     */
    public function save(int $trackerId, string $eventUuid, int $actorId, array $statuses): void
    {
        $this->database->startTransaction();
        try {
            $event = $this->database->queryPrepared(
                'SELECT dat_id, dat_begin FROM ' . self::TRACKERS . '
             LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
                  INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
             LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = eat_id AND eae_dat_id = dat_id
                 WHERE eat_id = ? AND dat_uuid = ? AND COALESCE(eae_cancelled, false) = false',
                array($trackerId, $eventUuid)
            )->fetch();
            if ($event === false) {
                throw new \InvalidArgumentException('Event is not configured for this tracker.');
            }
            $trackerEvent = $this->database->queryPrepared(
                'SELECT eae_id FROM ' . self::EVENTS . ' WHERE eae_tracker_id = ? AND eae_dat_id = ?',
                array($trackerId, (int) $event['dat_id'])
            )->fetch();
            if ($trackerEvent === false) {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::EVENTS . ' (eae_tracker_id, eae_dat_id) VALUES (?, ?)',
                    array($trackerId, (int) $event['dat_id'])
                );
                $trackerEventId = (int) $this->database->lastInsertId();
            } else {
                $trackerEventId = (int) $trackerEvent['eae_id'];
            }
            foreach ($statuses as $userId => $status) {
                $userId = (int) $userId;
                if ($userId <= 0 || !in_array($status, array('', 'present', 'excused', 'absent'), true)) {
                    throw new \InvalidArgumentException('Invalid attendance status.');
                }
                $expected = (int) $this->database->queryPrepared(
                    'SELECT COUNT(*) FROM ' . self::EXPECTED . ' WHERE eau_tracker_event_id = ? AND eau_usr_id = ?',
                    array($trackerEventId, $userId)
                )->fetchColumn();
                if ($expected === 0) {
                    $eligible = (int) $this->database->queryPrepared(
                        'SELECT COUNT(*) FROM ' . self::TRACKER_ROLES . '
                          INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
                         WHERE etr_tracker_id = ? AND mem_usr_id = ?
                           AND mem_begin <= DATE(?) AND mem_end > DATE(?)
                           AND (mem_approved IS NULL OR mem_approved < 3)',
                        array($trackerId, $userId, $event['dat_begin'], $event['dat_begin'])
                    )->fetchColumn();
                    if ($eligible === 0) {
                        throw new \InvalidArgumentException('User is not expected at this event.');
                    }
                    $this->database->queryPrepared(
                        'INSERT INTO ' . self::EXPECTED . ' (eau_tracker_event_id, eau_usr_id) VALUES (?, ?)',
                        array($trackerEventId, $userId)
                    );
                }
                $exists = (int) $this->database->queryPrepared(
                    'SELECT COUNT(*) FROM ' . self::RECORDS . ' WHERE ear_tracker_event_id = ? AND ear_usr_id = ?',
                    array($trackerEventId, $userId)
                )->fetchColumn();
                $hasDefaultExcuse = $status === '' && $this->database->queryPrepared(
                    'SELECT dat_id FROM ' . TBL_EVENTS . ' WHERE dat_id = ? AND '
                        . PermanentExcuseService::condition((string) $trackerId, (string) $userId, 'dat_begin'),
                    array((int) $event['dat_id'])
                )->fetch() !== false;
                if ($status === '' && !$hasDefaultExcuse) {
                    if ($exists > 0) {
                        $this->database->queryPrepared(
                            'DELETE FROM ' . self::RECORDS . ' WHERE ear_tracker_event_id = ? AND ear_usr_id = ?',
                            array($trackerEventId, $userId)
                        );
                    }
                } elseif ($exists > 0) {
                    $this->database->queryPrepared(
                        'UPDATE ' . self::RECORDS . ' SET ear_status = ?, ear_usr_id_change = ?, ear_timestamp_change = CURRENT_TIMESTAMP WHERE ear_tracker_event_id = ? AND ear_usr_id = ?',
                        array($status, $actorId, $trackerEventId, $userId)
                    );
                } else {
                    $this->database->queryPrepared(
                        'INSERT INTO ' . self::RECORDS . ' (ear_tracker_event_id, ear_usr_id, ear_status, ear_usr_id_create) VALUES (?, ?, ?, ?)',
                        array($trackerEventId, $userId, $status, $actorId)
                    );
                }
            }
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /**
     * Save a member's self-excuse for one tracker configuration.
     */
    public function excuseSelf(int $trackerId, string $eventUuid, int $userId): void
    {
        $this->database->startTransaction();

        try {
            $event = $this->database->queryPrepared(
                'SELECT dat_id
                   FROM ' . self::TRACKERS . '
             LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
                  INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
                  INNER JOIN ' . self::TRACKER_ROLES . ' ON etr_tracker_id = eat_id
                  INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
             LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = eat_id AND eae_dat_id = dat_id
                  WHERE eat_id = ?
                    AND dat_uuid = ?
                    AND mem_usr_id = ?
                    AND mem_begin <= DATE(dat_begin)
                    AND mem_end > DATE(dat_begin)
                    AND (mem_approved IS NULL OR mem_approved < 3)
                    AND dat_begin > CURRENT_TIMESTAMP
                    AND COALESCE(eae_cancelled, false) = false',
                array($trackerId, $eventUuid, $userId)
            )->fetch();

            if ($event === false) {
                throw new \InvalidArgumentException('User may not excuse themselves for this event.');
            }

            $trackerEvent = $this->database->queryPrepared(
                'SELECT eae_id FROM ' . self::EVENTS . ' WHERE eae_tracker_id = ? AND eae_dat_id = ?',
                array($trackerId, (int) $event['dat_id'])
            )->fetch();
            if ($trackerEvent === false) {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::EVENTS . ' (eae_tracker_id, eae_dat_id) VALUES (?, ?)',
                    array($trackerId, (int) $event['dat_id'])
                );
                $trackerEventId = (int) $this->database->lastInsertId();
            } else {
                $trackerEventId = (int) $trackerEvent['eae_id'];
            }

            $exists = (int) $this->database->queryPrepared(
                'SELECT COUNT(*) FROM ' . self::RECORDS . ' WHERE ear_tracker_event_id = ? AND ear_usr_id = ?',
                array($trackerEventId, $userId)
            )->fetchColumn();
            if ($exists === 1) {
                $this->database->queryPrepared(
                    'UPDATE ' . self::RECORDS . ' SET ear_status = ?, ear_usr_id_change = ?, ear_timestamp_change = CURRENT_TIMESTAMP WHERE ear_tracker_event_id = ? AND ear_usr_id = ?',
                    array('excused', $userId, $trackerEventId, $userId)
                );
            } else {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::RECORDS . ' (ear_tracker_event_id, ear_usr_id, ear_status, ear_usr_id_create) VALUES (?, ?, ?, ?)',
                    array($trackerEventId, $userId, 'excused', $userId)
                );
            }

            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /**
     * Remove an excusal that the member created themselves.
     */
    public function withdrawSelfExcuse(int $trackerId, string $eventUuid, int $userId): void
    {
        $this->database->queryPrepared(
            'DELETE FROM ' . self::RECORDS . '
              WHERE ear_status = ? AND ear_usr_id = ? AND ear_usr_id_create = ?
                AND ear_tracker_event_id IN (
                    SELECT eae_id FROM ' . self::EVENTS . '
                     INNER JOIN ' . TBL_EVENTS . ' ON dat_id = eae_dat_id
                     WHERE eae_tracker_id = ? AND dat_uuid = ? AND dat_begin > CURRENT_TIMESTAMP
                )',
            array('excused', $userId, $userId, $trackerId, $eventUuid)
        );
    }

    /**
     * Synchronize all self-excuses selected by one member.

     * @param array<int,string> $selectedEvents
     */
    public function saveSelfExcuses(int $organizationId, int $userId, int $deadlineHours, array $selectedEvents): void
    {
        $events = (new AttendanceData($this->database))->findSelfExcusableEvents($organizationId, $userId, $deadlineHours);
        foreach ($events as $event) {
            if (in_array($event['dat_uuid'], $selectedEvents, true)) {
                $this->excuseSelf((int) $event['eat_id'], $event['dat_uuid'], $userId);
            } elseif ($event['ear_status'] === 'excused') {
                $this->withdrawSelfExcuse((int) $event['eat_id'], $event['dat_uuid'], $userId);
            }
        }
    }

    /**
     * Change the self-excuse for every configuration that includes an event.
     */
    public function setSelfExcuseForEvent(int $organizationId, int $userId, int $deadlineHours, string $eventUuid, bool $excuse): void
    {
        $events = $this->findSelfExcuseEventConfigurations($organizationId, $userId, $deadlineHours, $eventUuid);
        if ($events === array()) {
            throw new \InvalidArgumentException('Event is not available for self-excuse.');
        }

        foreach ($events as $event) {
            if ($excuse) {
                $this->excuseSelf((int) $event['eat_id'], $event['dat_uuid'], $userId);
            } elseif ($event['ear_status'] === 'excused') {
                $this->withdrawSelfExcuse((int) $event['eat_id'], $event['dat_uuid'], $userId);
            }
        }
    }

    /**
     * Change one configuration and return how many other configurations contain the event.
     */
    public function setSelfExcuseForConfiguration(int $organizationId, int $userId, int $deadlineHours, string $trackerUuid, string $eventUuid, bool $excuse): int
    {
        $events = $this->findSelfExcuseEventConfigurations($organizationId, $userId, $deadlineHours, $eventUuid);
        $configuration = array_values(array_filter($events, static fn(array $event): bool => $event['eat_uuid'] === $trackerUuid))[0] ?? null;
        if ($configuration === null) {
            throw new \InvalidArgumentException('Tracker configuration is not available for self-excuse.');
        }

        if ($excuse) {
            $this->excuseSelf((int) $configuration['eat_id'], $eventUuid, $userId);
        } elseif ($configuration['ear_status'] === 'excused') {
            $this->withdrawSelfExcuse((int) $configuration['eat_id'], $eventUuid, $userId);
        }

        return count($events) - 1;
    }

    /**
     * Find an occurrence that is currently covered by a tracker configuration.
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    private function findConfiguredEvent(int $trackerId, string $eventUuid): array
    {
        $event = $this->database->queryPrepared(
            'SELECT dat_id FROM ' . self::TRACKERS . '
             LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
                  INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
                  WHERE eat_id = ? AND dat_uuid = ?',
            [$trackerId, $eventUuid]
        )->fetch();
        if ($event === false) {
            throw new \InvalidArgumentException('Event is not configured for this tracker.');
        }
        return $event;
    }

    /**
     * Find every tracker configuration for which a member may self-excuse an event.

     * @return array<int,array<string,mixed>>
     */
    private function findSelfExcuseEventConfigurations(int $organizationId, int $userId, int $deadlineHours, string $eventUuid): array
    {
        return array_values(array_filter(
            (new AttendanceData($this->database))->findSelfExcusableEvents($organizationId, $userId, $deadlineHours),
            static fn(array $event): bool => $event['dat_uuid'] === $eventUuid
        ));
    }
}
