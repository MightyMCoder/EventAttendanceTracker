<?php
/**
 * Contains the domain service that persists tracker configurations.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use Ramsey\Uuid\Uuid;

/**
 * Writes tracker configuration without exposing the plugin tables to presenters.
 */
final class TrackerService
{
    private const TRACKERS = TABLE_PREFIX . '_event_attendance_trackers';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';
    private const TRACKER_ROLES = TABLE_PREFIX . '_event_attendance_tracker_roles';

    /** Create the tracker service with the active database connection. */
    public function __construct(private readonly Database $database)
    {
    }

    /** Determine whether the current user may manage tracker configurations. */
    public static function mayManage(): bool
    {
        global $gCurrentUser;

        if ($gCurrentUser->isAdministrator()) {
            return true;
        }

        $roles = LegacyPluginContext::instance()->settings()['event_attendance_tracker_manager_roles'] ?? array();

        return count(array_intersect(array_map('intval', $roles), $gCurrentUser->getRoleMemberships())) > 0;
    }

    /** Determine whether the current user may use self-excuse actions. */
    public static function mayExcuseSelf(): bool
    {
        global $gCurrentUser;

        $settings = LegacyPluginContext::instance()->settings();
        if (!(bool) ($settings['event_attendance_members_may_excuse_themselves'] ?? false)) {
            return false;
        }

        if ($gCurrentUser->isAdministrator()) {
            return true;
        }

        return count(array_intersect(
            array_map('intval', $settings['event_attendance_self_excuse_roles'] ?? array()),
            $gCurrentUser->getRoleMemberships()
        )) > 0;
    }

    /** @param array<string,mixed> $formValues */
    public function saveForm(int $organizationId, int $userId, string $mode, array $formValues, ?string $trackerUuid = null): void
    {
        $calendarId = ($formValues['tracker_source'] ?? 'events') === 'calendar' ? (int) ($formValues['tracker_calendar'] ?? 0) : null;
        $arguments = array($organizationId, $userId, (string) $formValues['tracker_name'], (string) $formValues['tracker_description'], $formValues['tracker_roles'] ?? array(), $formValues['tracker_events'] ?? array(), $calendarId);
        if ($mode === 'save') {
            $this->create(...$arguments);
            return;
        }
        if ($mode !== 'save_edit' || $trackerUuid === null) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        array_splice($arguments, 1, 0, $trackerUuid);
        $this->update(...$arguments);
    }

    /** Activate or deactivate a tracker configuration. */
    public function setActive(int $organizationId, int $userId, string $trackerUuid, bool $active): void
    {
        if ($active) {
            $this->activate($organizationId, $trackerUuid, $userId);
            return;
        }
        $this->deactivate($organizationId, $trackerUuid, $userId);
    }

    /**
     * Create a tracker and atomically save its selected roles and event source.
     *
     * The caller supplies IDs resolved in the current organization. This service verifies that
     * every referenced object still belongs to that organization before writing any relation.
     * @param array<int,int> $roleIds
     * @param array<int,int> $eventIds
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
            throw new \InvalidArgumentException('A tracker must select an event or calendar.');
        }

        if ($calendarId !== null) {
            $eventIds = array();
        }
        $this->assertOrganizationReferences($organizationId, $roleIds, $eventIds, $calendarId);
        if ($calendarId === null) {
            $this->assertFutureEventReferences($eventIds);
        }
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
            throw new \InvalidArgumentException('A tracker must select an event or calendar.');
        }
        if ($calendarId !== null) {
            $eventIds = array();
        }
        $this->assertOrganizationReferences($organizationId, $roleIds, $eventIds, $calendarId);
        if ($calendarId === null) {
            $this->assertFutureEventReferences($eventIds);
        }

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
                'UPDATE ' . self::TRACKERS . ' SET eat_name = ?, eat_description = ?, eat_cat_id = ?, eat_source_start = ' . ($sourceChanged ? 'CURRENT_TIMESTAMP' : 'eat_source_start') . ', eat_usr_id_change = ?, eat_timestamp_change = CURRENT_TIMESTAMP WHERE eat_id = ?',
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

    /** Deactivate an active tracker configuration. */
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

    /** Activate an inactive tracker configuration. */
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
     * Permanently removes a tracker and every dependent configuration, snapshot
     * and attendance record through the database foreign-key cascades.
     */
    public function delete(int $organizationId, string $trackerUuid): void
    {
        $this->database->startTransaction();
        try {
            $deleted = $this->database->queryPrepared(
                'DELETE FROM ' . self::TRACKERS . ' WHERE eat_org_id = ? AND eat_uuid = ?',
                [$organizationId, $trackerUuid]
            )->rowCount();
            if ($deleted !== 1) {
                throw new \InvalidArgumentException('Tracker does not belong to this organization.');
            }
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
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

    /** Ensure that a tracker name is unique in the organization. */
    private function assertNameAvailable(int $organizationId, string $name, ?int $excludedTrackerId = null): void
    {
        $parameters = array($organizationId, $name);
        $condition = '';
        if ($excludedTrackerId !== null) {
            $condition = ' AND eat_id <> ?';
            $parameters[] = $excludedTrackerId;
        }

        $nameExists = $this->database->queryPrepared(
            'SELECT 1 FROM ' . self::TRACKERS . ' WHERE eat_org_id = ? AND eat_name = ?' . $condition,
            $parameters
        )->fetchColumn();
        if ($nameExists !== false) {
            throw new Exception('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_NAME_EXISTS');
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
        if ($calendarId !== null) {
            $calendar = $this->database->queryPrepared(
                'SELECT cat_id FROM ' . TBL_CATEGORIES . ' WHERE cat_org_id = ? AND cat_type = ? AND cat_id = ?',
                [$organizationId, 'EVT', $calendarId]
            )->fetch();
            if ($calendar === false) {
                throw new \InvalidArgumentException('The selected calendar does not belong to this organization.');
            }
        }
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
