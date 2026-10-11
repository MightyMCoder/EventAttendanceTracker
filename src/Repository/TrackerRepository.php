<?php

namespace AdmidioPlugin\EventAttendance\Repository;

use Admidio\Infrastructure\Database;

final class TrackerRepository
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const TRACKERS = TABLE_PREFIX . '_event_attendance_trackers';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    private const TRACKER_ROLES = TABLE_PREFIX . '_event_attendance_tracker_roles';

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
            'SELECT eat_id, eat_uuid, eat_name, eat_description, eat_active
               FROM ' . self::TRACKERS . '
              WHERE eat_org_id = ? AND eat_uuid = ?',
            [$organizationId, $uuid]
        )->fetch();
    }

    /** @return array{roles: array<int,int>, events: array<int,int>} */
    public function findConfiguration(int $trackerId): array
    {
        return array(
            'roles' => array_map('intval', array_column($this->database->queryPrepared('SELECT etr_rol_id FROM ' . self::TRACKER_ROLES . ' WHERE etr_tracker_id = ?', [$trackerId])->fetchAll(), 'etr_rol_id')),
            'events' => array_map('intval', array_column($this->database->queryPrepared('SELECT ete_dat_id FROM ' . self::TRACKER_EVENTS . ' WHERE ete_tracker_id = ?', [$trackerId])->fetchAll(), 'ete_dat_id'))
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function findOccurrences(int $trackerId): array
    {
        return $this->database->queryPrepared(
            'SELECT DISTINCT dat_uuid, dat_headline, dat_begin, dat_end,
                    COALESCE(eae_cancelled, false) AS cancelled
               FROM ' . self::TRACKER_EVENTS . '
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id
         LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = ete_tracker_id AND eae_dat_id = dat_id
              WHERE ete_tracker_id = ?
              ORDER BY dat_begin DESC',
            [$trackerId]
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function findActiveOccurrences(int $organizationId): array
    {
        return $this->database->queryPrepared(
            'SELECT DISTINCT eat_uuid, eat_name, dat_uuid, dat_headline, dat_begin,
                    COALESCE(eae_cancelled, false) AS cancelled
               FROM ' . self::TRACKERS . '
              INNER JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id
         LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = eat_id AND eae_dat_id = dat_id
              WHERE eat_org_id = ? AND eat_active = true
              ORDER BY dat_begin DESC',
            [$organizationId]
        )->fetchAll();
    }
}
