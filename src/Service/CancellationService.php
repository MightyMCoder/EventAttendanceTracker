<?php

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use DateTimeImmutable;

final class CancellationService
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const TRACKERS = TABLE_PREFIX . '_event_attendance_trackers';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';

    public function __construct(private readonly Database $database)
    {
    }

    public function cancel(int $trackerId, string $eventUuid, int $actorId): void
    {
        $event = $this->database->queryPrepared(
            'SELECT dat_id, dat_uuid
               FROM ' . self::TRACKERS . '
          LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
              WHERE eat_id = ? AND dat_uuid = ?',
            [$trackerId, $eventUuid]
        )->fetch();
        if ($event === false) {
            throw new \InvalidArgumentException('Event is not configured for this tracker.');
        }

        $this->database->startTransaction();
        try {
            // A cancellation is part of the attendance history. Freeze the
            // expected attendees before setting the cancelled status so it is
            // retained in every report, including after membership changes.
            (new AttendanceSnapshotService($this->database))->snapshotEventWithinTransaction(
                $trackerId,
                (int) $event['dat_id'],
                new DateTimeImmutable(DATETIME_NOW)
            );
            $row = $this->database->queryPrepared(
                'SELECT eae_id FROM ' . self::EVENTS . ' WHERE eae_tracker_id = ? AND eae_dat_id = ?',
                [$trackerId, (int) $event['dat_id']]
            )->fetch();
            if ($row === false) {
                throw new \LogicException('Could not create the tracker event snapshot.');
            }
            $this->database->queryPrepared(
                'UPDATE ' . self::EVENTS . ' SET eae_cancelled = true, eae_cancelled_by_usr_id = ?, eae_cancelled_at = CURRENT_TIMESTAMP WHERE eae_id = ?',
                [$actorId, (int) $row['eae_id']]
            );
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

}
