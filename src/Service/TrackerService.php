<?php

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Ramsey\Uuid\Uuid;

/**
 * Writes tracker configuration without exposing the plugin tables to presenters.
 */
final class TrackerService
{
    private const TRACKERS = TABLE_PREFIX . '_event_attendance_trackers';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    private const TRACKER_ROLES = TABLE_PREFIX . '_event_attendance_tracker_roles';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Create a tracker and atomically save its selected roles and individual events.
     *
     * The caller supplies IDs resolved in the current organization. This service verifies that
     * every referenced object still belongs to that organization before writing any relation.
     * @param array<int,int> $roleIds
     * @param array<int,int> $eventIds
     * @param array<int,int> $recurrenceIds
     */
    public function create(
        int $organizationId,
        int $actorId,
        string $name,
        string $description,
        array $roleIds,
        array $eventIds,
        array $recurrenceIds
    ): string {
        $name = trim($name);
        if ($organizationId <= 0 || $actorId <= 0 || $name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Invalid tracker data.');
        }

        $roleIds = $this->uniquePositiveIds($roleIds);
        $eventIds = $this->uniquePositiveIds($eventIds);
        if ($eventIds === []) {
            throw new \InvalidArgumentException('A tracker must select at least one event.');
        }

        $this->assertOrganizationReferences($organizationId, $roleIds, $eventIds);
        $this->assertFutureEventReferences($eventIds);

        $uuid = (string)Uuid::uuid4();
        $this->database->startTransaction();
        try {
            $this->database->queryPrepared(
                'INSERT INTO ' . self::TRACKERS . '
                    (eat_org_id, eat_uuid, eat_name, eat_description, eat_active, eat_usr_id_create)
                 VALUES (?, ?, ?, ?, true, ?)',
                [$organizationId, $uuid, $name, $description, $actorId]
            );
            $trackerId = $this->database->lastInsertId();

            foreach ($roleIds as $roleId) {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::TRACKER_ROLES . ' (etr_tracker_id, etr_rol_id) VALUES (?, ?)',
                    [$trackerId, $roleId]
                );
            }
            foreach ($eventIds as $eventId) {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::TRACKER_EVENTS . ' (ete_tracker_id, ete_dat_id) VALUES (?, ?)',
                    [$trackerId, $eventId]
                );
            }
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }

        return $uuid;
    }

    /**
     * Update only configuration that will affect future snapshots. Existing snapshot rows stay
     * untouched, so past attendance remains reproducible.
     * @param array<int,int> $roleIds
     * @param array<int,int> $eventIds
     * @param array<int,int> $recurrenceIds
     */
    public function update(
        int $organizationId,
        string $trackerUuid,
        int $actorId,
        string $name,
        string $description,
        array $roleIds,
        array $eventIds,
        array $recurrenceIds
    ): void {
        $name = trim($name);
        if ($organizationId <= 0 || $actorId <= 0 || $trackerUuid === '' || $name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Invalid tracker data.');
        }

        $roleIds = $this->uniquePositiveIds($roleIds);
        $eventIds = $this->uniquePositiveIds($eventIds);
        $this->assertOrganizationReferences($organizationId, $roleIds, $eventIds);
        $this->assertFutureEventReferences($eventIds);

        $tracker = $this->database->queryPrepared(
            'SELECT eat_id FROM ' . self::TRACKERS . ' WHERE eat_org_id = ? AND eat_uuid = ?',
            [$organizationId, $trackerUuid]
        )->fetch();
        if ($tracker === false) {
            throw new \InvalidArgumentException('Tracker does not belong to this organization.');
        }

        $trackerId = (int) $tracker['eat_id'];
        // Persist the roster of past and already started occurrences before replacing relations.
        // Reports can then remain based on this immutable snapshot after a configuration edit.
        (new AttendanceSnapshotService($this->database))->snapshotDueEventsForTracker(
            $trackerId,
            new \DateTimeImmutable(DATETIME_NOW)
        );
        $this->database->startTransaction();
        try {
            $this->database->queryPrepared(
                'UPDATE ' . self::TRACKERS . ' SET eat_name = ?, eat_description = ?, eat_usr_id_change = ?, eat_timestamp_change = CURRENT_TIMESTAMP WHERE eat_id = ?',
                [$name, $description, $actorId, $trackerId]
            );
            $this->database->queryPrepared('DELETE FROM ' . self::TRACKER_ROLES . ' WHERE etr_tracker_id = ?', [$trackerId]);
            $this->database->queryPrepared('DELETE FROM ' . self::TRACKER_EVENTS . ' WHERE ete_tracker_id = ?', [$trackerId]);
            $this->writeRelations($trackerId, $roleIds, $eventIds);
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    public function deactivate(int $organizationId, string $trackerUuid, int $actorId): void
    {
        $changed = $this->database->queryPrepared(
            'UPDATE ' . self::TRACKERS . ' SET eat_active = false, eat_usr_id_change = ?, eat_timestamp_change = CURRENT_TIMESTAMP WHERE eat_org_id = ? AND eat_uuid = ? AND eat_active = true',
            [$actorId, $organizationId, $trackerUuid]
        )->rowCount();
        if ($changed !== 1) {
            throw new \InvalidArgumentException('Tracker is not active or does not belong to this organization.');
        }
    }

    public function activate(int $organizationId, string $trackerUuid, int $actorId): void
    {
        $changed = $this->database->queryPrepared(
            'UPDATE ' . self::TRACKERS . ' SET eat_active = true, eat_usr_id_change = ?, eat_timestamp_change = CURRENT_TIMESTAMP WHERE eat_org_id = ? AND eat_uuid = ? AND eat_active = false',
            [$actorId, $organizationId, $trackerUuid]
        )->rowCount();
        if ($changed !== 1) {
            throw new \InvalidArgumentException('Tracker is already active or does not belong to this organization.');
        }
    }

    /** @param array<int,int> $roleIds @param array<int,int> $eventIds */
    private function writeRelations(int $trackerId, array $roleIds, array $eventIds): void
    {
        foreach ($roleIds as $roleId) {
            $this->database->queryPrepared('INSERT INTO ' . self::TRACKER_ROLES . ' (etr_tracker_id, etr_rol_id) VALUES (?, ?)', [$trackerId, $roleId]);
        }
        foreach ($eventIds as $eventId) {
            $this->database->queryPrepared('INSERT INTO ' . self::TRACKER_EVENTS . ' (ete_tracker_id, ete_dat_id) VALUES (?, ?)', [$trackerId, $eventId]);
        }
    }

    /** @param array<int,int> $ids @return array<int,int> */
    private function uniquePositiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    /** @param array<int,int> $roleIds @param array<int,int> $eventIds */
    private function assertOrganizationReferences(int $organizationId, array $roleIds, array $eventIds): void
    {
        $this->assertCount('SELECT rol_id FROM ' . TBL_ROLES . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id WHERE cat_org_id = ? AND rol_id IN ', $organizationId, $roleIds);
        $this->assertCount('SELECT dat_id FROM ' . TBL_EVENTS . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = dat_cat_id WHERE cat_org_id = ? AND dat_id IN ', $organizationId, $eventIds);
    }

    /** @param array<int,int> $eventIds */
    private function assertFutureEventReferences(array $eventIds): void
    {
        $this->assertFutureCount('SELECT dat_id FROM ' . TBL_EVENTS . ' WHERE dat_begin > CURRENT_TIMESTAMP AND dat_id IN ', $eventIds);
    }

    /** @param array<int,int> $ids */
    private function assertFutureCount(string $sql, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $rows = $this->database->queryPrepared(
            $sql . '(' . Database::getQmForValues($ids) . ')',
            $ids
        )->fetchAll();
        if (count($rows) !== count($ids)) {
            throw new \InvalidArgumentException('A selected event or recurrence is no longer in the future.');
        }
    }

    /** @param array<int,int> $ids */
    private function assertCount(string $sql, int $organizationId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $rows = $this->database->queryPrepared(
            $sql . '(' . Database::getQmForValues($ids) . ')',
            array_merge([$organizationId], $ids)
        )->fetchAll();
        if (count($rows) !== count($ids)) {
            throw new \InvalidArgumentException('A selected reference does not belong to the organization.');
        }
    }
}
