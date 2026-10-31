<?php
/**
 * Contains business logic for recording event attendance.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;

final class AttendanceService
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const EXPECTED = TABLE_PREFIX . '_event_attendance_expected_users';
    private const RECORDS = TABLE_PREFIX . '_event_attendance_records';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    private const TRACKER_ROLES = TABLE_PREFIX . '_event_attendance_tracker_roles';

    public function __construct(private readonly Database $database)
    {
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
    public function saveForm(int $trackerId, string $eventUuid, int $userId, array $formValues, ProfileFields $profileFields): void
    {
        (new AttendanceSnapshotService($this->database))->snapshotDueEventsForTracker($trackerId, new \DateTimeImmutable(DATETIME_NOW));
        $roster = (new AttendanceRepository($this->database))->findRoster($trackerId, $eventUuid, $profileFields);
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

    /** @param array<int,string> $statuses */
    public function save(int $trackerId, string $eventUuid, int $actorId, array $statuses): void
    {
        $this->database->startTransaction();
        try {
            $event = $this->database->queryPrepared(
                'SELECT dat_id, dat_begin FROM ' . TABLE_PREFIX . '_event_attendance_trackers
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

    public function excuseSelf(int $trackerId, string $eventUuid, int $userId): void
    {
        $this->database->startTransaction();

        try {
            $event = $this->database->queryPrepared(
                'SELECT dat_id
                   FROM ' . TABLE_PREFIX . '_event_attendance_trackers
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
     * Restore a previously cancelled attendance occurrence.
     *
     * @param int    $trackerId The tracker containing the event.
     * @param string $eventUuid The public UUID of the event.
     * @return void
     */
    public function restore(int $trackerId, string $eventUuid): void
    {
        $event = $this->findConfiguredEvent($trackerId, $eventUuid);
        $restored = $this->database->queryPrepared(
            'UPDATE ' . self::EVENTS . '
                SET eae_cancelled = false,
                    eae_cancelled_by_usr_id = NULL,
                    eae_cancelled_at = NULL
              WHERE eae_tracker_id = ? AND eae_dat_id = ? AND eae_cancelled = true',
            array($trackerId, (int) $event['dat_id'])
        )->rowCount();
        if ($restored !== 1) {
            throw new \InvalidArgumentException('Event is not cancelled for this tracker.');
        }
    }

    /** Change the self-excuse for every eligible configuration of an event. */
    public function setSelfExcuseForEvent(int $organizationId, int $userId, int $deadlineHours, string $eventUuid, bool $excuse): void
    {
        $events = $this->findSelfExcuseEventConfigurations($organizationId, $userId, $deadlineHours, $eventUuid);
        if ($events === array()) {
            throw new \InvalidArgumentException('Event is not available for self-excuse.');
        }

        foreach ($events as $event) {
            if ($excuse) {
                $this->excuseSelf((int) $event['eat_id'], $eventUuid, $userId);
            } elseif ($event['ear_status'] === 'excused') {
                $this->withdrawSelfExcuse((int) $event['eat_id'], $eventUuid, $userId);
            }
        }
    }

    /** Change one configuration and return the number of further configurations. */
    public function setSelfExcuseForConfiguration(int $organizationId, int $userId, int $deadlineHours, string $trackerUuid, string $eventUuid, bool $excuse): int
    {
        $events = $this->findSelfExcuseEventConfigurations($organizationId, $userId, $deadlineHours, $eventUuid);
        $event = array_values(array_filter($events, static fn (array $entry): bool => $entry['eat_uuid'] === $trackerUuid))[0] ?? null;
        if ($event === null) {
            throw new \InvalidArgumentException('Tracker configuration is not available for self-excuse.');
        }
        if ($excuse) {
            $this->excuseSelf((int) $event['eat_id'], $eventUuid, $userId);
        } elseif ($event['ear_status'] === 'excused') {
            $this->withdrawSelfExcuse((int) $event['eat_id'], $eventUuid, $userId);
        }
        return count($events) - 1;
    }

    /** @return array<int,array<string,mixed>> */
    private function findSelfExcuseEventConfigurations(int $organizationId, int $userId, int $deadlineHours, string $eventUuid): array
    {
        return array_values(array_filter(
            (new \AdmidioPlugin\EventAttendance\Repository\AttendanceRepository($this->database))->findSelfExcusableEvents($organizationId, $userId, $deadlineHours),
            static fn (array $event): bool => $event['dat_uuid'] === $eventUuid
        ));
    }

    /**
     * Find an occurrence that is configured for the given tracker.
     *
     * @param int    $trackerId The tracker identifier.
     * @param string $eventUuid The public event UUID.
     * @return array<string,mixed>
     */
    private function findConfiguredEvent(int $trackerId, string $eventUuid): array
    {
        $event = $this->database->queryPrepared(
            'SELECT dat_id FROM ' . TABLE_PREFIX . '_event_attendance_trackers
         LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
              WHERE eat_id = ? AND dat_uuid = ?',
            array($trackerId, $eventUuid)
        )->fetch();
        if ($event === false) {
            throw new \InvalidArgumentException('Event is not configured for this tracker.');
        }

        return $event;
    }

    /** Remove an excusal that the member created themselves. */
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
}
