<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;

/** Synchronizes a member's selected self-excuses. */
final class SelfExcuseActionService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<int,string> $selectedEvents */
    public function save(int $organizationId, int $userId, int $deadlineHours, array $selectedEvents): void
    {
        $events = (new AttendanceRepository($this->database))->findSelfExcusableEvents($organizationId, $userId, $deadlineHours);
        $attendance = new AttendanceService($this->database);
        foreach ($events as $event) {
            if (in_array($event['eat_uuid'] . ':' . $event['dat_uuid'], $selectedEvents, true)) {
                $attendance->excuseSelf((int) $event['eat_id'], $event['dat_uuid'], $userId);
                continue;
            }
            if ($event['ear_status'] === 'excused') {
                $attendance->withdrawSelfExcuse((int) $event['eat_id'], $event['dat_uuid'], $userId);
            }
        }
    }
}
