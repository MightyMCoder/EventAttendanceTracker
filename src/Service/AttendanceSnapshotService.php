<?php
/**
 * Contains snapshot processing for event-attendance occurrences.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

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
    private const TRACKERS = TABLE_PREFIX . '_event_attendance_trackers';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    private const TRACKER_ROLES = TABLE_PREFIX . '_event_attendance_tracker_roles';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Bring all selected events up to date. Safe to invoke from a cron job and web requests.
     */
    public function closeDueEvents(DateTimeInterface $now, ?int $organizationId = null): void
    {
        $this->database->startTransaction();

        try {
            $this->closeDueEventsWithinTransaction($now, $organizationId);
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /**
     * Bring all selected events up to date within a transaction owned by the caller.
     *
     * The scheduler uses this method so its row lock covers snapshotting and status updates.
     */
    public function closeDueEventsWithinTransaction(DateTimeInterface $now, ?int $organizationId = null): void
    {
        $nowValue = $now->format('Y-m-d H:i:s');
        $organizationFilter = $organizationId === null ? '' : ' AND eat_org_id = ?';
        $parameters = array($nowValue);
        if ($organizationId !== null) {
            $parameters[] = $organizationId;
        }
        $selected = $this->database->queryPrepared(
            'SELECT DISTINCT eat_id AS ete_tracker_id, dat_id
               FROM ' . self::TRACKERS . '
          LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
              WHERE eat_active = true AND dat_begin <= ?' . $organizationFilter,
            $parameters
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
              INNER JOIN ' . self::TRACKERS . ' ON eat_id = eae_tracker_id
         LEFT JOIN ' . self::RECORDS . ' ON ear_tracker_event_id = eau_tracker_event_id AND ear_usr_id = eau_usr_id
              WHERE ear_id IS NULL
                AND eae_cancelled = false
                AND COALESCE(dat_end, dat_begin) <= ?' . $organizationFilter,
            $organizationId === null ? array('absent', $nowValue) : array('absent', $nowValue, $organizationId)
        );
    }

    /** Freeze the currently configured occurrences of one tracker up to the given instant. */
    public function snapshotDueEventsForTracker(int $trackerId, DateTimeInterface $now): void
    {
        $this->database->startTransaction();

        try {
            $nowValue = $now->format('Y-m-d H:i:s');
            $events = $this->database->queryPrepared(
                'SELECT DISTINCT dat_id FROM ' . self::TRACKERS . '
              LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
                  INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
                 WHERE eat_id = ? AND dat_begin <= ?',
                [$trackerId, $nowValue]
            );
            foreach ($events->fetchAll() as $event) {
                $this->snapshot($trackerId, (int) $event['dat_id'], $nowValue);
            }
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /**
     * Snapshot an occurrence within a transaction owned by the caller.
     *
     * Cancellation uses this for future events so later role or membership
     * changes cannot alter the expected attendees of a cancelled occurrence.
     */
    public function snapshotEventWithinTransaction(int $trackerId, int $eventId, DateTimeInterface $now): void
    {
        $this->snapshot($trackerId, $eventId, $now->format('Y-m-d H:i:s'));
    }

    /**
     * Store the expected-user snapshot for one concrete tracker event exactly once.
     */
    private function snapshot(int $trackerId, int $eventId, string $snapshotAt): void
    {
        $event = $this->database->queryPrepared(
            'SELECT dat_begin
               FROM ' . TBL_EVENTS . '
              WHERE dat_id = ?',
            [$eventId]
        )->fetch();
        if ($event === false) {
            return;
        }

        $existing = $this->database->queryPrepared(
            'SELECT eae_id, eae_snapshot_at
               FROM ' . self::EVENTS . '
              WHERE eae_tracker_id = ? AND eae_dat_id = ?',
            [$trackerId, $eventId]
        )->fetch();
        if ($existing !== false && $existing['eae_snapshot_at'] !== null) {
            return;
        }
        if ($existing === false) {
            $this->database->queryPrepared(
                'INSERT INTO ' . self::EVENTS . ' (eae_tracker_id, eae_dat_id) VALUES (?, ?)',
                [$trackerId, $eventId]
            );
            $trackerEventId = $this->database->lastInsertId();
        } else {
            $trackerEventId = (int) $existing['eae_id'];
        }

        $members = $this->database->queryPrepared(
            'SELECT DISTINCT mem_usr_id
               FROM ' . self::TRACKER_ROLES . '
         INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
              WHERE etr_tracker_id = ?
                AND mem_begin <= ?
                AND mem_end > ?
                AND (mem_approved IS NULL OR mem_approved < 3)',
            [$trackerId, substr((string) $event['dat_begin'], 0, 10), substr((string) $event['dat_begin'], 0, 10)]
        );
        while ($member = $members->fetch()) {
            $this->database->queryPrepared(
                'INSERT INTO ' . self::EXPECTED_USERS . ' (eau_tracker_event_id, eau_usr_id)
                 SELECT ?, ? WHERE NOT EXISTS (
                     SELECT 1 FROM ' . self::EXPECTED_USERS . ' WHERE eau_tracker_event_id = ? AND eau_usr_id = ?
                 )',
                [$trackerEventId, (int) $member['mem_usr_id'], $trackerEventId, (int) $member['mem_usr_id']]
            );
        }
        $this->database->queryPrepared(
            'UPDATE ' . self::EVENTS . ' SET eae_snapshot_at = ? WHERE eae_id = ?',
            [$snapshotAt, $trackerEventId]
        );
    }
}
