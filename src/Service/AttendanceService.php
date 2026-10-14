<?php
/**
 * Contains shared attendance domain operations.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use DateTimeImmutable;

final class AttendanceService
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const EXPECTED = TABLE_PREFIX . '_event_attendance_expected_users';
    private const RECORDS = TABLE_PREFIX . '_event_attendance_records';
    private const TRACKERS = TABLE_PREFIX . '_event_attendance_trackers';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    private const TRACKER_ROLES = TABLE_PREFIX . '_event_attendance_tracker_roles';

    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<string,mixed> $formValues */
    public function saveForm(int $trackerId, string $eventUuid, int $userId, array $formValues, mixed $profileFields): void
    {
        (new AttendanceSnapshotService($this->database))->snapshotDueEventsForTracker($trackerId, new DateTimeImmutable(DATETIME_NOW));
        $roster = (new \AdmidioPlugin\EventAttendance\ValueObject\AttendanceData($this->database))->findRoster($trackerId, $eventUuid, $profileFields);
        $statuses = array();
        foreach ($roster as $person) {
            $statuses[(int) $person['eau_usr_id']] = (string) ($formValues['attendance_status_' . (int) $person['eau_usr_id']] ?? '');
        }
        $this->save($trackerId, $eventUuid, $userId, $statuses);
    }

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

    public function restore(int $trackerId, string $eventUuid): void
    {
        $event = $this->findConfiguredEvent($trackerId, $eventUuid);
        $restored = $this->database->queryPrepared('UPDATE ' . self::EVENTS . ' SET eae_cancelled = false, eae_cancelled_by_usr_id = NULL, eae_cancelled_at = NULL WHERE eae_tracker_id = ? AND eae_dat_id = ? AND eae_cancelled = true', [$trackerId, (int) $event['dat_id']])->rowCount();
        if ($restored !== 1) {
            throw new \InvalidArgumentException('Event is not cancelled for this tracker.');
        }
    }
    /** @param array<int,string> $statuses */
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
                if ($status === '') {
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

    /** @return array<string,mixed> */
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
}
