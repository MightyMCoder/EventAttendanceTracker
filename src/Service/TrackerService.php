<?php

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
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
     * Create a tracker and atomically save its selected roles and event source.
     *
     * The caller supplies IDs resolved in the current organization. This service verifies that
     * every referenced object still belongs to that organization before writing any relation.
     * @param array<int,int> $roleIds
     * @param array<int,int> $eventIds
     * @param int|null $calendarId
     */
    public function create(
        int $organizationId,
        int $actorId,
        string $name,
        string $description,
        array $roleIds,
        array $eventIds,
        ?int $calendarId
    ): string {
        $name = trim($name);
        if ($organizationId <= 0 || $actorId <= 0 || $name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Invalid tracker data.');
        }

        $roleIds = $this->uniquePositiveIds($roleIds);
        $eventIds = $this->uniquePositiveIds($eventIds);
        $calendarId = $calendarId !== null && $calendarId > 0 ? $calendarId : null;
        if ($calendarId === null && $eventIds === []) {
            throw new \InvalidArgumentException('A tracker must select at least one event or calendar.');
        }
        if ($calendarId !== null) {
            $eventIds = array();
        }

        $this->assertOrganizationReferences($organizationId, $roleIds, $eventIds, $calendarId);
        $this->assertFutureEventReferences($eventIds);
        $this->assertNameAvailable($organizationId, $name);

        $uuid = (string)Uuid::uuid4();
        $this->database->startTransaction();
        try {
            $this->database->queryPrepared(
                'INSERT INTO ' . self::TRACKERS . '
                    (eat_org_id, eat_uuid, eat_name, eat_description, eat_cat_id, eat_active, eat_usr_id_create)
                 VALUES (?, ?, ?, ?, ?, true, ?)',
                [$organizationId, $uuid, $name, $description, $calendarId, $actorId]
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
     * @param int|null $calendarId
     */
    public function update(
        int $organizationId,
        string $trackerUuid,
        int $actorId,
        string $name,
        string $description,
        array $roleIds,
        array $eventIds,
        ?int $calendarId
    ): void {
        $name = trim($name);
        if ($organizationId <= 0 || $actorId <= 0 || $trackerUuid === '' || $name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Invalid tracker data.');
        }

        $roleIds = $this->uniquePositiveIds($roleIds);
        $eventIds = $this->uniquePositiveIds($eventIds);
        $calendarId = $calendarId !== null && $calendarId > 0 ? $calendarId : null;
        if ($calendarId === null && $eventIds === []) {
            throw new \InvalidArgumentException('A tracker must select at least one event or calendar.');
        }
        if ($calendarId !== null) {
            $eventIds = array();
        }
        $this->assertOrganizationReferences($organizationId, $roleIds, $eventIds, $calendarId);
        $this->assertFutureEventReferences($eventIds);

        $tracker = $this->database->queryPrepared(
            'SELECT eat_id, eat_cat_id FROM ' . self::TRACKERS . ' WHERE eat_org_id = ? AND eat_uuid = ?',
            [$organizationId, $trackerUuid]
        )->fetch();
        if ($tracker === false) {
            throw new \InvalidArgumentException('Tracker does not belong to this organization.');
        }

        $trackerId = (int) $tracker['eat_id'];
        $this->assertNameAvailable($organizationId, $name, $trackerId);
        $sourceChanged = (int) ($tracker['eat_cat_id'] ?? 0) !== (int) ($calendarId ?? 0);
        // Persist the roster of past and already started occurrences before replacing relations.
        // Reports can then remain based on this immutable snapshot after a configuration edit.
        (new AttendanceSnapshotService($this->database))->snapshotDueEventsForTracker(
            $trackerId,
            new \DateTimeImmutable(DATETIME_NOW)
        );
        $this->database->startTransaction();
        try {
            $this->database->queryPrepared(
            'UPDATE ' . self::TRACKERS . ' SET eat_name = ?, eat_description = ?, eat_cat_id = ?, eat_source_start = '
                . ($sourceChanged ? 'CURRENT_TIMESTAMP' : 'eat_source_start')
                . ', eat_usr_id_change = ?, eat_timestamp_change = CURRENT_TIMESTAMP WHERE eat_id = ?',
                [$name, $description, $calendarId, $actorId, $trackerId]
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

    /**
     * Delete a tracker and all attendance data which belongs exclusively to it.
     *
     * The foreign-key cascade removes configured event and role relations, snapshots and
     * attendance records. The referenced Admidio events, users and roles are never changed.
     *
     * @param int    $organizationId The organization that owns the tracker.
     * @param string $trackerUuid    The public UUID of the tracker.
     * @return void
     */
    public function delete(int $organizationId, string $trackerUuid): void
    {
        $deleted = $this->database->queryPrepared(
            'DELETE FROM ' . self::TRACKERS . ' WHERE eat_org_id = ? AND eat_uuid = ?',
            array($organizationId, $trackerUuid)
        )->rowCount();
        if ($deleted !== 1) {
            throw new \InvalidArgumentException('Tracker does not belong to this organization.');
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
    private function assertOrganizationReferences(int $organizationId, array $roleIds, array $eventIds, ?int $calendarId): void
    {
        $this->assertCount('SELECT rol_id FROM ' . TBL_ROLES . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id WHERE cat_org_id = ? AND rol_id IN ', $organizationId, $roleIds);
        $this->assertCount('SELECT dat_id FROM ' . TBL_EVENTS . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = dat_cat_id WHERE cat_org_id = ? AND dat_id IN ', $organizationId, $eventIds);
        if ($calendarId !== null && $this->database->queryPrepared(
            'SELECT cat_id FROM ' . TBL_CATEGORIES . ' WHERE cat_org_id = ? AND cat_type = ? AND cat_id = ?',
            array($organizationId, 'EVT', $calendarId)
        )->fetch() === false) {
            throw new \InvalidArgumentException('The selected calendar does not belong to this organization.');
        }
    }

    /** @param array<int,int> $eventIds */
    private function assertFutureEventReferences(array $eventIds): void
    {
        $this->assertFutureCount('SELECT dat_id FROM ' . TBL_EVENTS . ' WHERE dat_begin > CURRENT_TIMESTAMP AND dat_id IN ', $eventIds);
    }

    /**
     * Ensure that the tracker name is unique in its organization.
     */
    private function assertNameAvailable(int $organizationId, string $name, ?int $excludedTrackerId = null): void
    {
        $parameters = array($organizationId, $name);
        $condition = '';
        if ($excludedTrackerId !== null) {
            $condition = ' AND eat_id <> ?';
            $parameters[] = $excludedTrackerId;
        }

        if ($this->database->queryPrepared(
            'SELECT 1 FROM ' . self::TRACKERS . ' WHERE eat_org_id = ? AND eat_name = ?' . $condition,
            $parameters
        )->fetchColumn() !== false) {
            throw new Exception('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_NAME_EXISTS');
        }
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
            throw new \InvalidArgumentException('A selected event is no longer in the future.');
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
