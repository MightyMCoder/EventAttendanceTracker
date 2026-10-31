<?php
/**
 * Contains database queries for event-attendance tracker configurations.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

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
            'SELECT eat_uuid, eat_name, eat_description, eat_active, eat_cat_id, cat_name AS calendar_name
               FROM ' . self::TRACKERS . '
          LEFT JOIN ' . TBL_CATEGORIES . ' ON cat_id = eat_cat_id
              WHERE eat_org_id = ?
              ORDER BY eat_name',
            [$organizationId]
        )->fetchAll();
    }

    /** @return array<string,mixed>|false */
    public function findByUuid(int $organizationId, string $uuid): array|false
    {
        return $this->database->queryPrepared(
            'SELECT eat_id, eat_uuid, eat_name, eat_description, eat_active, eat_cat_id, eat_source_start
               FROM ' . self::TRACKERS . '
              WHERE eat_org_id = ? AND eat_uuid = ?',
            [$organizationId, $uuid]
        )->fetch();
    }

    /** @return array{roles: array<int,int>, events: array<int,int>, calendar: int} */
    public function findConfiguration(int $trackerId): array
    {
        return array(
            'roles' => array_map('intval', array_column($this->database->queryPrepared('SELECT etr_rol_id FROM ' . self::TRACKER_ROLES . ' WHERE etr_tracker_id = ?', [$trackerId])->fetchAll(), 'etr_rol_id')),
            'events' => array_map('intval', array_column($this->database->queryPrepared('SELECT ete_dat_id FROM ' . self::TRACKER_EVENTS . ' WHERE ete_tracker_id = ? AND ete_dat_id IS NOT NULL', [$trackerId])->fetchAll(), 'ete_dat_id')),
            'calendar' => (int) $this->database->queryPrepared('SELECT COALESCE(eat_cat_id, 0) FROM ' . self::TRACKERS . ' WHERE eat_id = ?', [$trackerId])->fetchColumn()
        );
    }

    /**
     * Determine the default report period of a tracker.
     *
     * @return array{dateFrom:string,dateUntil:?string}
     */
    public function getReportPeriodDefaults(int $trackerId): array
    {
        $period = $this->database->queryPrepared(
            'SELECT DATE(eat_timestamp_create) AS date_from,
                    MAX(DATE(COALESCE(dat_end, dat_begin))) AS date_until
               FROM ' . self::TRACKERS . '
          LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
               LEFT JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
              WHERE eat_id = ?
              GROUP BY eat_id, eat_timestamp_create',
            array($trackerId)
        )->fetch();

        return array(
            'dateFrom' => (string) ($period['date_from'] ?? ''),
            'dateUntil' => isset($period['date_until']) ? (string) $period['date_until'] : null
        );
    }

    /**
     * Return all occurrences of a tracker within an optional report period.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findOccurrences(int $trackerId, string $period = 'all', ?string $dateFrom = null, ?string $dateUntil = null): array
    {
        $periodCondition = match ($period) {
            'past' => ' AND COALESCE(dat_end, dat_begin) < CURRENT_TIMESTAMP',
            'future' => ' AND COALESCE(dat_end, dat_begin) >= CURRENT_TIMESTAMP',
            default => ''
        };
        $dateCondition = '';
        $parameters = array($trackerId);
        if ($dateFrom !== null) {
            $dateCondition .= ' AND DATE(dat_begin) >= ?';
            $parameters[] = $dateFrom;
        }
        if ($dateUntil !== null) {
            $dateCondition .= ' AND DATE(dat_begin) <= ?';
            $parameters[] = $dateUntil;
        }

        return $this->database->queryPrepared(
            'SELECT DISTINCT dat_uuid, dat_headline, dat_begin, dat_end,
                    COALESCE(eae_cancelled, false) AS cancelled
               FROM ' . self::TRACKERS . '
          LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
         LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = eat_id AND eae_dat_id = dat_id
              WHERE eat_id = ?' . $periodCondition . $dateCondition . '
              ORDER BY dat_begin ASC',
            $parameters
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function findActiveOccurrences(int $organizationId, string $period = 'all'): array
    {
        $periodCondition = match ($period) {
            'past' => ' AND COALESCE(dat_end, dat_begin) < CURRENT_TIMESTAMP',
            'future' => ' AND COALESCE(dat_end, dat_begin) >= CURRENT_TIMESTAMP',
            default => ''
        };

        return $this->database->queryPrepared(
            'SELECT DISTINCT eat_uuid, eat_name, dat_uuid, dat_headline, dat_begin,
                    COALESCE(dat_end, dat_begin) AS dat_end, eae_snapshot_at,
                    COALESCE(eae_cancelled, false) AS cancelled
               FROM ' . self::TRACKERS . '
         LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
         LEFT JOIN ' . self::EVENTS . ' ON eae_tracker_id = eat_id AND eae_dat_id = dat_id
              WHERE eat_org_id = ? AND eat_active = true' . $periodCondition . '
              ORDER BY dat_begin ASC',
            [$organizationId]
        )->fetchAll();
    }
}
