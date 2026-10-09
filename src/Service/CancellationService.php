<?php
namespace AdmidioPlugin\EventAttendance\Service;
use Admidio\Infrastructure\Database;
final class CancellationService
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events'; private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    public function __construct(private readonly Database $database) {}
    public function cancel(int $trackerId, string $eventUuid, int $actorId): void
    {
        $eventId = (int)$this->database->queryPrepared('SELECT dat_id FROM ' . TBL_EVENTS . ' INNER JOIN ' . self::TRACKER_EVENTS . ' ON dat_id = ete_dat_id OR dat_evr_id = ete_evr_id WHERE ete_tracker_id = ? AND dat_uuid = ?', [$trackerId, $eventUuid])->fetchColumn();
        if ($eventId === 0) throw new \InvalidArgumentException('Event is not configured for this tracker.');
        $row = $this->database->queryPrepared('SELECT eae_id FROM ' . self::EVENTS . ' WHERE eae_tracker_id = ? AND eae_dat_id = ?', [$trackerId, $eventId])->fetch();
        if ($row === false) { $this->database->queryPrepared('INSERT INTO ' . self::EVENTS . ' (eae_tracker_id, eae_dat_id) VALUES (?, ?)', [$trackerId, $eventId]); $id = $this->database->lastInsertId(); } else $id = (int)$row['eae_id'];
        $this->database->queryPrepared('UPDATE ' . self::EVENTS . ' SET eae_cancelled = true, eae_cancelled_by_usr_id = ?, eae_cancelled_at = CURRENT_TIMESTAMP WHERE eae_id = ?', [$actorId, $id]);
    }
}
