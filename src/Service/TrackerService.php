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
     * Create a tracker and atomically save its selected roles, events and recurrences.
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
        $recurrenceIds = $this->uniquePositiveIds($recurrenceIds);
        if ($eventIds === [] && $recurrenceIds === []) {
            throw new \InvalidArgumentException('A tracker must select at least one event or recurrence.');
        }

        $this->assertOrganizationReferences($organizationId, $roleIds, $eventIds, $recurrenceIds);

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
            foreach ($recurrenceIds as $recurrenceId) {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::TRACKER_EVENTS . ' (ete_tracker_id, ete_evr_id) VALUES (?, ?)',
                    [$trackerId, $recurrenceId]
                );
            }
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }

        return $uuid;
    }

    /** @param array<int,int> $ids @return array<int,int> */
    private function uniquePositiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    /** @param array<int,int> $roleIds @param array<int,int> $eventIds @param array<int,int> $recurrenceIds */
    private function assertOrganizationReferences(int $organizationId, array $roleIds, array $eventIds, array $recurrenceIds): void
    {
        $this->assertCount('SELECT rol_id FROM ' . TBL_ROLES . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id WHERE cat_org_id = ? AND rol_id IN ', $organizationId, $roleIds);
        $this->assertCount('SELECT dat_id FROM ' . TBL_EVENTS . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = dat_cat_id WHERE cat_org_id = ? AND dat_id IN ', $organizationId, $eventIds);
        $this->assertCount('SELECT evr_id FROM ' . TBL_EVENT_RECURRENCES . ' INNER JOIN ' . TBL_EVENTS . ' ON dat_id = evr_dat_id_master INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = dat_cat_id WHERE cat_org_id = ? AND evr_id IN ', $organizationId, $recurrenceIds);
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
        )->rowCount();
        if ($rows !== count($ids)) {
            throw new \InvalidArgumentException('A selected reference does not belong to the organization.');
        }
    }
}
