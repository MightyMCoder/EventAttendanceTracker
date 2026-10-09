<?php

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use DateTimeInterface;

/**
 * Freezes expected attendees at event start and closes overdue attendance records.
 */
final class AttendanceSnapshotService
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const EXPECTED_USERS = TABLE_PREFIX . '_event_attendance_expected_users';
    private const RECORDS = TABLE_PREFIX . '_event_attendance_records';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    private const TRACKER_ROLES = TABLE_PREFIX . '_event_attendance_tracker_roles';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Bring all selected events up to date. Safe to invoke from a cron job and web requests.
     */
    public function closeDueEvents(DateTimeInterface $now): void
    {
        $nowValue = $now->format('Y-m-d H:i:s');
        $selected = $this->database->queryPrepared(
            'SELECT DISTINCT ete_tracker_id, dat_id
               FROM ' . self::TRACKER_EVENTS . '
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR dat_evr_id = ete_evr_id
              WHERE dat_begin <= ?',
            [$nowValue]
        );
        foreach ($selected->fetchAll() as $row) {
            $this->snapshot((int)$row['ete_tracker_id'], (int)$row['dat_id'], $nowValue);
        }

        $this->database->queryPrepared(
            'INSERT INTO ' . self::RECORDS . ' (ear_tracker_event_id, ear_usr_id, ear_status)
             SELECT eau_tracker_event_id, eau_usr_id, ?
               FROM ' . self::EXPECTED_USERS . '
              INNER JOIN ' . self::EVENTS . ' ON eae_id = eau_tracker_event_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = eae_dat_id
         LEFT JOIN ' . self::RECORDS . ' ON ear_tracker_event_id = eau_tracker_event_id AND ear_usr_id = eau_usr_id
              WHERE ear_id IS NULL
                AND eae_cancelled = false
                AND COALESCE(dat_end, dat_begin) <= ?',
            ['absent', $nowValue]
        );
    }

    /**
     * Store the expected-user snapshot for one concrete tracker event exactly once.
     */
    private function snapshot(int $trackerId, int $eventId, string $snapshotAt): void
    {
        $this->database->startTransaction();
        try {
            $event = $this->database->queryPrepared(
                'SELECT dat_begin
                   FROM ' . TBL_EVENTS . '
                  WHERE dat_id = ?',
                [$eventId]
            )->fetch();
            if ($event === false) {
                $this->database->rollback();
                return;
            }

            $existing = $this->database->queryPrepared(
                'SELECT eae_id, eae_snapshot_at
                   FROM ' . self::EVENTS . '
                  WHERE eae_tracker_id = ? AND eae_dat_id = ?',
                [$trackerId, $eventId]
            )->fetch();
            if ($existing !== false && $existing['eae_snapshot_at'] !== null) {
                $this->database->endTransaction();
                return;
            }
            if ($existing === false) {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::EVENTS . ' (eae_tracker_id, eae_dat_id) VALUES (?, ?)',
                    [$trackerId, $eventId]
                );
                $trackerEventId = $this->database->lastInsertId();
            } else {
                $trackerEventId = (int)$existing['eae_id'];
            }

            $members = $this->database->queryPrepared(
                'SELECT DISTINCT mem_usr_id
                   FROM ' . self::TRACKER_ROLES . '
             INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
                  WHERE etr_tracker_id = ?
                    AND mem_begin <= ?
                    AND mem_end > ?
                    AND (mem_approved IS NULL OR mem_approved < 3)',
                [$trackerId, substr((string)$event['dat_begin'], 0, 10), substr((string)$event['dat_begin'], 0, 10)]
            );
            while ($member = $members->fetch()) {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::EXPECTED_USERS . ' (eau_tracker_event_id, eau_usr_id) VALUES (?, ?)',
                    [$trackerEventId, (int)$member['mem_usr_id']]
                );
            }
            $this->database->queryPrepared(
                'UPDATE ' . self::EVENTS . ' SET eae_snapshot_at = ? WHERE eae_id = ?',
                [$snapshotAt, $trackerEventId]
            );
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }
}
