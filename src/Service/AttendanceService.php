<?php
namespace AdmidioPlugin\EventAttendance\Service;
use Admidio\Infrastructure\Database;
final class AttendanceService
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events'; private const EXPECTED = TABLE_PREFIX . '_event_attendance_expected_users'; private const RECORDS = TABLE_PREFIX . '_event_attendance_records';
    public function __construct(private readonly Database $database) {}
    /** @param array<int,string> $statuses */
    public function save(int $trackerId, string $eventUuid, int $actorId, array $statuses): void
    {
        $this->database->startTransaction(); try {
            $eventId = (int)$this->database->queryPrepared('SELECT eae_id FROM ' . self::EVENTS . ' INNER JOIN ' . TBL_EVENTS . ' ON dat_id = eae_dat_id WHERE eae_tracker_id = ? AND dat_uuid = ?', [$trackerId, $eventUuid])->fetchColumn();
            foreach ($statuses as $userId => $status) { if ($status === '') continue; $userId = (int)$userId; if ($userId <= 0 || !in_array($status, ['present', 'excused', 'absent'], true)) throw new \InvalidArgumentException('Invalid attendance status.'); $expected = (int)$this->database->queryPrepared('SELECT COUNT(*) FROM ' . self::EXPECTED . ' WHERE eau_tracker_event_id = ? AND eau_usr_id = ?', [$eventId, $userId])->fetchColumn(); if ($eventId === 0 || $expected !== 1) throw new \InvalidArgumentException('User is not expected at this event.'); $exists = (int)$this->database->queryPrepared('SELECT COUNT(*) FROM ' . self::RECORDS . ' WHERE ear_tracker_event_id = ? AND ear_usr_id = ?', [$eventId, $userId])->fetchColumn(); if ($exists) $this->database->queryPrepared('UPDATE ' . self::RECORDS . ' SET ear_status = ?, ear_usr_id_change = ?, ear_timestamp_change = CURRENT_TIMESTAMP WHERE ear_tracker_event_id = ? AND ear_usr_id = ?', [$status, $actorId, $eventId, $userId]); else $this->database->queryPrepared('INSERT INTO ' . self::RECORDS . ' (ear_tracker_event_id, ear_usr_id, ear_status, ear_usr_id_create) VALUES (?, ?, ?, ?)', [$eventId, $userId, $status, $actorId]); }
            $this->database->endTransaction();
        } catch (\Throwable $e) { $this->database->rollback(); throw $e; }
    }

    public function excuseSelf(int $trackerId, string $eventUuid, int $userId): void
    {
        $this->save($trackerId, $eventUuid, $userId, [$userId => 'excused']);
    }
}
