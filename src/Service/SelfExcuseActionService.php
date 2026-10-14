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
            if (in_array($event['dat_uuid'], $selectedEvents, true)) {
                $attendance->excuseSelf((int) $event['eat_id'], $event['dat_uuid'], $userId);
                continue;
            }
            if ($event['ear_status'] === 'excused') {
                $attendance->withdrawSelfExcuse((int) $event['eat_id'], $event['dat_uuid'], $userId);
            }
        }
    }

    /**
     * Changes the self-excuse for all tracker configurations of one event.
     */
    public function setForEvent(int $organizationId, int $userId, int $deadlineHours, string $eventUuid, bool $excuse): void
    {
        $events = $this->findEventConfigurations($organizationId, $userId, $deadlineHours, $eventUuid);
        if ($events === array()) {
            throw new \InvalidArgumentException('Event is not available for self-excuse.');
        }

        $attendance = new AttendanceService($this->database);
        foreach ($events as $event) {
            if ($excuse) {
                $attendance->excuseSelf((int) $event['eat_id'], $event['dat_uuid'], $userId);
            } elseif ($event['ear_status'] === 'excused') {
                $attendance->withdrawSelfExcuse((int) $event['eat_id'], $event['dat_uuid'], $userId);
            }
        }
    }

    /**
     * Changes one tracker configuration and returns the number of other
     * configurations that contain the same calendar event.
     */
    public function setForConfiguration(
        int $organizationId,
        int $userId,
        int $deadlineHours,
        string $trackerUuid,
        string $eventUuid,
        bool $excuse
    ): int {
        $events = $this->findEventConfigurations($organizationId, $userId, $deadlineHours, $eventUuid);
        $configuration = array_values(array_filter(
            $events,
            static fn(array $event): bool => $event['eat_uuid'] === $trackerUuid
        ))[0] ?? null;
        if ($configuration === null) {
            throw new \InvalidArgumentException('Tracker configuration is not available for self-excuse.');
        }

        $attendance = new AttendanceService($this->database);
        if ($excuse) {
            $attendance->excuseSelf((int) $configuration['eat_id'], $eventUuid, $userId);
        } elseif ($configuration['ear_status'] === 'excused') {
            $attendance->withdrawSelfExcuse((int) $configuration['eat_id'], $eventUuid, $userId);
        }

        return count($events) - 1;
    }

    /** @return array<int,array<string,mixed>> */
    private function findEventConfigurations(int $organizationId, int $userId, int $deadlineHours, string $eventUuid): array
    {
        return array_values(array_filter(
            (new AttendanceRepository($this->database))->findSelfExcusableEvents($organizationId, $userId, $deadlineHours),
            static fn(array $event): bool => $event['dat_uuid'] === $eventUuid
        ));
    }
}
