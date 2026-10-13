<?php

namespace AdmidioPlugin\EventAttendance\Repository;

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;

final class AttendanceRepository
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

    /** @return array<int,array<string,mixed>> */
    public function findRoster(int $trackerId, string $eventUuid, ProfileFields $fields): array
    {
        return $this->database->queryPrepared('SELECT DISTINCT roster.usr_id AS eau_usr_id, first_name.usd_value AS first_name, last_name.usd_value AS last_name, ear_status
            FROM (
                SELECT eau_usr_id AS usr_id, eae_id AS tracker_event_id
                  FROM ' . self::EVENTS . '
            INNER JOIN ' . TBL_EVENTS . ' ON dat_id = eae_dat_id
            INNER JOIN ' . self::EXPECTED . ' ON eau_tracker_event_id = eae_id
                 WHERE eae_tracker_id = ? AND dat_uuid = ?
                UNION
                SELECT mem_usr_id AS usr_id, eae_id AS tracker_event_id
                  FROM ' . self::TRACKERS . '
             LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
            INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
            INNER JOIN ' . self::TRACKER_ROLES . ' ON etr_tracker_id = eat_id
            INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
             LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = eat_id AND eae_dat_id = dat_id
                 WHERE eat_id = ? AND dat_uuid = ?
                   AND mem_begin <= DATE(dat_begin) AND mem_end > DATE(dat_begin)
                   AND (mem_approved IS NULL OR mem_approved < 3)
            ) AS roster
            LEFT JOIN ' . self::RECORDS . ' ON ear_tracker_event_id = roster.tracker_event_id AND ear_usr_id = roster.usr_id
            LEFT JOIN ' . TBL_USER_DATA . ' AS first_name ON first_name.usd_usr_id = roster.usr_id AND first_name.usd_usf_id = ?
            LEFT JOIN ' . TBL_USER_DATA . ' AS last_name ON last_name.usd_usr_id = roster.usr_id AND last_name.usd_usf_id = ?
            ORDER BY last_name.usd_value, first_name.usd_value', [$trackerId, $eventUuid, $trackerId, $eventUuid, $fields->getProperty('FIRST_NAME', 'usf_id'), $fields->getProperty('LAST_NAME', 'usf_id')])->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function findSummary(
        int $trackerId,
        ProfileFields $fields,
        bool $countExcusedForRate = false,
        ?string $dateFrom = null,
        ?string $dateUntil = null
    ): array
    {
        $dateCondition = '';
        $dateParameters = array();
        if ($dateFrom !== null) {
            $dateCondition .= ' AND DATE(dat_begin) >= ?';
            $dateParameters[] = $dateFrom;
        }
        if ($dateUntil !== null) {
            $dateCondition .= ' AND DATE(dat_begin) <= ?';
            $dateParameters[] = $dateUntil;
        }

        $events = $this->database->queryPrepared(
            'SELECT attendees.usr_id AS eau_usr_id,
                    first_name.usd_value AS first_name,
                    last_name.usd_value AS last_name,
                    SUM(CASE WHEN attendees.cancelled = false THEN 1 ELSE 0 END) AS expected_count,
                    SUM(CASE WHEN attendees.cancelled = false AND attendees.status = ? THEN 1 ELSE 0 END) AS present_count,
                    SUM(CASE WHEN attendees.cancelled = false AND attendees.status = ? THEN 1 ELSE 0 END) AS excused_count,
                    SUM(CASE WHEN attendees.cancelled = false AND attendees.status = ? THEN 1 ELSE 0 END) AS absent_count,
                    SUM(CASE WHEN attendees.cancelled = false AND attendees.status IS NULL THEN 1 ELSE 0 END) AS unknown_count,
                    SUM(CASE WHEN attendees.cancelled = true THEN 1 ELSE 0 END) AS cancelled_count,
                    SUM(CASE WHEN attendees.cancelled = false AND attendees.event_end <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END) AS completed_count,
                    COALESCE(
                        100.0 * SUM(CASE WHEN attendees.cancelled = false AND (attendees.status = ? OR (? = 1 AND attendees.status = ?)) AND attendees.event_end <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN attendees.cancelled = false AND attendees.event_end <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END), 0), 0) AS attendance_percentage
               FROM (
                    SELECT eau_usr_id AS usr_id, COALESCE(dat_end, dat_begin) AS event_end,
                           eae_cancelled AS cancelled, ear_status AS status
                      FROM ' . self::EVENTS . '
                INNER JOIN ' . TBL_EVENTS . ' ON dat_id = eae_dat_id
                INNER JOIN ' . self::EXPECTED . ' ON eau_tracker_event_id = eae_id
                 LEFT JOIN ' . self::RECORDS . ' ON ear_tracker_event_id = eae_id AND ear_usr_id = eau_usr_id
                     WHERE eae_tracker_id = ? AND eae_snapshot_at IS NOT NULL' . $dateCondition . '
                    UNION ALL
                    SELECT mem_usr_id AS usr_id, COALESCE(dat_end, dat_begin) AS event_end,
                           COALESCE(eae_cancelled, false) AS cancelled, ear_status AS status
                      FROM ' . self::TRACKERS . '
                 LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
                INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
                INNER JOIN ' . self::TRACKER_ROLES . ' ON etr_tracker_id = eat_id
                INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
                 LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = eat_id AND eae_dat_id = dat_id
                 LEFT JOIN ' . self::RECORDS . ' ON ear_tracker_event_id = eae_id AND ear_usr_id = mem_usr_id
                     WHERE eat_id = ? AND eae_snapshot_at IS NULL' . $dateCondition . '
                       AND mem_begin <= DATE(dat_begin) AND mem_end > DATE(dat_begin)
                       AND (mem_approved IS NULL OR mem_approved < 3)
               ) AS attendees
         LEFT JOIN ' . TBL_USER_DATA . ' AS first_name ON first_name.usd_usr_id = attendees.usr_id AND first_name.usd_usf_id = ?
         LEFT JOIN ' . TBL_USER_DATA . ' AS last_name ON last_name.usd_usr_id = attendees.usr_id AND last_name.usd_usf_id = ?
              GROUP BY attendees.usr_id, first_name.usd_value, last_name.usd_value
              ORDER BY last_name.usd_value, first_name.usd_value',
            [
                'present',
                'excused',
                'absent',
                'present',
                $countExcusedForRate ? 1 : 0,
                'excused',
                $trackerId,
                ...$dateParameters,
                $trackerId,
                ...$dateParameters,
                $fields->getProperty('FIRST_NAME', 'usf_id'),
                $fields->getProperty('LAST_NAME', 'usf_id'),
            ]
        )->fetchAll();

        return $events;
    }

    /** @return array<int,array<string,mixed>> */
    public function findSelfExcusableEvents(int $organizationId, int $userId, int $deadlineHours = 0): array
    {
        $events = $this->database->queryPrepared(
            'SELECT DISTINCT eat_id, eat_uuid, eat_name, dat_uuid, dat_headline, dat_begin, ear_status
               FROM ' . self::TRACKERS . '
         LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
              INNER JOIN ' . self::TRACKER_ROLES . ' ON etr_tracker_id = eat_id
              INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
         LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = eat_id AND eae_dat_id = dat_id
         LEFT JOIN ' . self::RECORDS . ' ON ear_tracker_event_id = eae_id AND ear_usr_id = ?
              WHERE eat_org_id = ?
                AND eat_active = true
                AND mem_usr_id = ?
                AND mem_begin <= DATE(dat_begin)
                AND mem_end > DATE(dat_begin)
                AND (mem_approved IS NULL OR mem_approved < 3)
                AND dat_begin > CURRENT_TIMESTAMP
                AND COALESCE(eae_cancelled, false) = false
              ORDER BY dat_begin, eat_name, dat_headline',
            [$userId, $organizationId, $userId]
        )->fetchAll();

        if ($deadlineHours <= 0) {
            return $events;
        }

        $deadline = (new \DateTimeImmutable(DATETIME_NOW))->modify('+' . $deadlineHours . ' hours');
        return array_values(array_filter($events, static fn (array $event): bool => new \DateTimeImmutable($event['dat_begin']) > $deadline));
    }
}
