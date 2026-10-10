<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Tests\Integration;

use Admidio\Events\Entity\Event;
use Admidio\Infrastructure\Database;
use Admidio\Tests\Support\AdmidioTestFixture;
use Admidio\Tests\Support\DatabaseTestCase;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;
use AdmidioPlugin\EventAttendance\Service\AttendanceService;
use AdmidioPlugin\EventAttendance\Service\TrackerService;

// Plugin services use Admidio's database-table constants during class loading. The integration
// bootstrap loads them lazily, whereas PHPUnit discovers this file first.
require_once dirname(__DIR__, 4) . '/tests/constants.php';
if (!defined('TBL_EVENT_RECURRENCES')) {
    define('TBL_EVENT_RECURRENCES', TABLE_PREFIX . '_event_recurrences');
}
require_once dirname(__DIR__, 2) . '/src/Repository/AttendanceRepository.php';
require_once dirname(__DIR__, 2) . '/src/Service/AttendanceService.php';
require_once dirname(__DIR__, 2) . '/src/Service/AttendanceSnapshotService.php';
require_once dirname(__DIR__, 2) . '/src/Service/TrackerService.php';

final class AttendanceWorkflowTest extends DatabaseTestCase
{
    private const ORGANIZATION_ID = 1;
    private static bool $schemaInstalledByTest = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $table = TABLE_PREFIX . '_event_attendance_trackers';
        if (!self::$gDb->tableExists($table)) {
            foreach (Database::getSqlStatementsFromSqlFile(dirname(__DIR__, 2) . '/db_scripts/install.sql') as $statement) {
                self::$gDb->queryPrepared($statement);
            }
            self::$schemaInstalledByTest = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$schemaInstalledByTest) {
            foreach (Database::getSqlStatementsFromSqlFile(dirname(__DIR__, 2) . '/db_scripts/uninstall.sql') as $statement) {
                self::$gDb->queryPrepared($statement);
            }
        }

        parent::tearDownAfterClass();
    }

    public function testRecordedStatusCanBeResetToNotRecorded(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $member = $fixture->createAndSaveUser('attendance-reset', 'attendance-reset@example.test');
        $role = $fixture->createAndSaveRole('Attendance reset role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($member['usr_id'], $role['rol_id']);
        $event = $this->createEvent('Attendance reset event', '2030-06-15 18:00:00', '2030-06-15 20:00:00');

        $trackerId = $this->createTracker('Attendance reset tracker', [$role['rol_id']], [$event['id']]);
        $service = new AttendanceService($this->getDatabase());
        $service->save($trackerId, $event['uuid'], 1, [$member['usr_id'] => 'present']);
        $service->save($trackerId, $event['uuid'], 1, [$member['usr_id'] => '']);

        $recordCount = (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . TABLE_PREFIX . '_event_attendance_records
              INNER JOIN ' . TABLE_PREFIX . '_event_attendance_events ON eae_id = ear_tracker_event_id
             WHERE eae_tracker_id = ? AND ear_usr_id = ?',
            [$trackerId, $member['usr_id']]
        )->fetchColumn();

        $this->assertSame(0, $recordCount);
    }

    public function testUpdatingTrackerPreservesPastSnapshotInSummary(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $historicalMember = $fixture->createAndSaveUser('snapshot-member', 'snapshot-member@example.test');
        $historicalRole = $fixture->createAndSaveRole('Historical attendance role', self::ORGANIZATION_ID);
        $replacementRole = $fixture->createAndSaveRole('Replacement attendance role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($historicalMember['usr_id'], $historicalRole['rol_id']);
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_MEMBERS . ' SET mem_begin = ?, mem_end = ? WHERE mem_rol_id = ? AND mem_usr_id = ?',
            ['2020-01-01', '9999-12-31', $historicalRole['rol_id'], $historicalMember['usr_id']]
        );

        // The tracker can only be created for a future event. Move the occurrence into the past
        // afterwards to exercise the snapshot that update() takes before replacing relations.
        $pastEvent = $this->createEvent('Snapshot source event', '2030-07-01 18:00:00', '2030-07-01 20:00:00');
        $replacementEvent = $this->createEvent('Replacement event', '2031-07-01 18:00:00', '2031-07-01 20:00:00');
        $tracker = new TrackerService($this->getDatabase());
        $trackerUuid = $tracker->create(
            self::ORGANIZATION_ID,
            1,
            'Snapshot history tracker',
            '',
            [$historicalRole['rol_id']],
            [$pastEvent['id']],
            []
        );
        $trackerId = $this->trackerId($trackerUuid);
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_EVENTS . ' SET dat_begin = ?, dat_end = ? WHERE dat_id = ?',
            ['2020-07-01 18:00:00', '2020-07-01 20:00:00', $pastEvent['id']]
        );
        (new AttendanceService($this->getDatabase()))->save(
            $trackerId,
            $pastEvent['uuid'],
            1,
            [$historicalMember['usr_id'] => 'present']
        );

        $tracker->update(
            self::ORGANIZATION_ID,
            $trackerUuid,
            1,
            'Snapshot history tracker',
            '',
            [$replacementRole['rol_id']],
            [$replacementEvent['id']],
            []
        );

        $summary = (new AttendanceRepository($this->getDatabase()))->findSummary(
            $trackerId,
            $GLOBALS['gProfileFields']
        );
        $row = array_values(array_filter($summary, static fn (array $entry): bool => (int) $entry['eau_usr_id'] === $historicalMember['usr_id']))[0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['expected_count']);
        $this->assertSame(1, (int) $row['present_count']);
    }

    /** @return array{id: int, uuid: string} */
    private function createEvent(string $headline, string $begin, string $end): array
    {
        $categoryId = (int) $this->getDatabase()->queryPrepared(
            'SELECT cat_id FROM ' . TBL_CATEGORIES . ' WHERE cat_type = ? AND cat_org_id = ? ORDER BY cat_id',
            ['EVT', self::ORGANIZATION_ID]
        )->fetchColumn();
        $event = new Event($this->getDatabase());
        $event->saveChangesWithoutRights();
        $event->setValue('dat_cat_id', $categoryId);
        $event->setValue('dat_headline', $headline);
        $event->setValue('dat_begin', $begin);
        $event->setValue('dat_end', $end);
        $event->save();

        return array('id' => (int) $event->getValue('dat_id'), 'uuid' => (string) $event->getValue('dat_uuid'));
    }

    /** @param array<int,int> $roleIds @param array<int,int> $eventIds */
    private function createTracker(string $name, array $roleIds, array $eventIds): int
    {
        $uuid = (new TrackerService($this->getDatabase()))->create(
            self::ORGANIZATION_ID,
            1,
            $name,
            '',
            $roleIds,
            $eventIds,
            []
        );

        return $this->trackerId($uuid);
    }

    private function trackerId(string $uuid): int
    {
        return (int) $this->getDatabase()->queryPrepared(
            'SELECT eat_id FROM ' . TABLE_PREFIX . '_event_attendance_trackers WHERE eat_uuid = ?',
            [$uuid]
        )->fetchColumn();
    }
}
