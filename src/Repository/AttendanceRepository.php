<?php
namespace AdmidioPlugin\EventAttendance\Repository;
use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
final class AttendanceRepository
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const EXPECTED = TABLE_PREFIX . '_event_attendance_expected_users';
    private const RECORDS = TABLE_PREFIX . '_event_attendance_records';
    public function __construct(private readonly Database $database) {}
    /** @return array<int,array<string,mixed>> */
    public function findRoster(int $trackerId, string $eventUuid, ProfileFields $fields): array
    {
        return $this->database->queryPrepared('SELECT eau_usr_id, first_name.usd_value AS first_name, last_name.usd_value AS last_name, ear_status
            FROM ' . self::EVENTS . ' INNER JOIN ' . TBL_EVENTS . ' ON dat_id = eae_dat_id INNER JOIN ' . self::EXPECTED . ' ON eau_tracker_event_id = eae_id
            LEFT JOIN ' . self::RECORDS . ' ON ear_tracker_event_id = eae_id AND ear_usr_id = eau_usr_id
            LEFT JOIN ' . TBL_USER_DATA . ' AS first_name ON first_name.usd_usr_id = eau_usr_id AND first_name.usd_usf_id = ?
            LEFT JOIN ' . TBL_USER_DATA . ' AS last_name ON last_name.usd_usr_id = eau_usr_id AND last_name.usd_usf_id = ?
            WHERE eae_tracker_id = ? AND dat_uuid = ? ORDER BY last_name.usd_value, first_name.usd_value', [$fields->getProperty('FIRST_NAME', 'usf_id'), $fields->getProperty('LAST_NAME', 'usf_id'), $trackerId, $eventUuid])->fetchAll();
    }
}
