<?php

namespace AdmidioPlugin\EventAttendance\Repository;

use Admidio\Infrastructure\Database;

final class TrackerRepository
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const TRACKERS = TABLE_PREFIX . '_event_attendance_trackers';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';

    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function findAll(int $organizationId): array
    {
        return $this->database->queryPrepared(
            'SELECT eat_uuid, eat_name, eat_description, eat_active
               FROM ' . self::TRACKERS . '
              WHERE eat_org_id = ?
              ORDER BY eat_name',
            [$organizationId]
        )->fetchAll();
    }

    /** @return array<string,mixed>|false */
    public function findByUuid(int $organizationId, string $uuid): array|false
    {
        return $this->database->queryPrepared(
            'SELECT eat_id, eat_uuid, eat_name
               FROM ' . self::TRACKERS . '
              WHERE eat_org_id = ? AND eat_uuid = ?',
            [$organizationId, $uuid]
        )->fetch();
    }

    /** @return array<int,array<string,mixed>> */
    public function findOccurrences(int $trackerId): array
    {
        return $this->database->queryPrepared(
            'SELECT DISTINCT dat_uuid, dat_headline, dat_begin, dat_end,
                    COALESCE(eae_cancelled, false) AS cancelled
               FROM ' . self::TRACKER_EVENTS . '
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR dat_evr_id = ete_evr_id
         LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = ete_tracker_id AND eae_dat_id = dat_id
              WHERE ete_tracker_id = ?
              ORDER BY dat_begin DESC',
            [$trackerId]
        )->fetchAll();
    }
}
