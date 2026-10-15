<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Tests\Integration;

use Admidio\Events\Entity\Event;
use Admidio\Infrastructure\Database;
use Admidio\Tests\Support\AdmidioTestFixture;
use Admidio\Tests\Support\DatabaseTestCase;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;
use AdmidioPlugin\EventAttendance\Repository\TrackerRepository;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportService;
use AdmidioPlugin\EventAttendance\Service\AttendanceService;
use AdmidioPlugin\EventAttendance\Service\AttendanceSnapshotService;
use AdmidioPlugin\EventAttendance\Service\SpreadsheetValueEscaper;
use AdmidioPlugin\EventAttendance\Service\TrackerService;

// Plugin services use Admidio's database-table constants during class loading. The integration
// bootstrap loads them lazily, whereas PHPUnit discovers this file first.
require_once dirname(__DIR__, 4) . '/tests/constants.php';
if (!defined('TBL_EVENT_RECURRENCES')) {
    define('TBL_EVENT_RECURRENCES', TABLE_PREFIX . '_event_recurrences');
}
require_once dirname(__DIR__, 2) . '/src/Repository/AttendanceRepository.php';
require_once dirname(__DIR__, 2) . '/src/Repository/TrackerRepository.php';
require_once dirname(__DIR__, 2) . '/src/Service/AttendanceAutomationService.php';
require_once dirname(__DIR__, 2) . '/src/Service/AttendanceReportService.php';
require_once dirname(__DIR__, 2) . '/src/Service/AttendanceService.php';
require_once dirname(__DIR__, 2) . '/src/Service/AttendanceSnapshotService.php';
require_once dirname(__DIR__, 2) . '/src/Service/SpreadsheetValueEscaper.php';
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

        $summary = (new AttendanceRepository($this->getDatabase()))->findSummary(
            $trackerId,
            $GLOBALS['gProfileFields']
        );
        $this->assertSame(0.0, (float) $summary[0]['attendance_percentage']);
    }

    public function testCancelledEventCannotReceiveAttendanceRecords(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $member = $fixture->createAndSaveUser('cancelled-attendance', 'cancelled-attendance@example.test');
        $role = $fixture->createAndSaveRole('Cancelled attendance role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($member['usr_id'], $role['rol_id']);
        $event = $this->createEvent('Cancelled attendance event', '2030-08-15 18:00:00', '2030-08-15 20:00:00');
        $trackerId = $this->createTracker('Cancelled attendance tracker', [$role['rol_id']], [$event['id']]);

        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_events (eae_tracker_id, eae_dat_id, eae_cancelled) VALUES (?, ?, true)',
            [$trackerId, $event['id']]
        );

        $this->expectException(\InvalidArgumentException::class);
        (new AttendanceService($this->getDatabase()))->save(
            $trackerId,
            $event['uuid'],
            1,
            [$member['usr_id'] => 'present']
        );
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
            null
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
            null
        );

        $summary = (new AttendanceRepository($this->getDatabase()))->findSummary(
            $trackerId,
            $GLOBALS['gProfileFields']
        );
        $row = array_values(array_filter($summary, static fn (array $entry): bool => (int) $entry['eau_usr_id'] === $historicalMember['usr_id']))[0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['expected_count']);
        $this->assertSame(1, (int) $row['present_count']);
        $this->assertSame(100.0, (float) $row['attendance_percentage']);

        $reportRows = (new AttendanceReportService(
            $this->getDatabase(),
            $GLOBALS['gProfileFields'],
            array('event_attendance_rate_decimal_places' => 0),
            $GLOBALS['gL10n']
        ))->getRows($trackerId);
        $this->assertSame('100', $reportRows[0]['attendance_percentage_display']);
    }

    public function testDeactivatedTrackerIsExcludedFromActiveOccurrences(): void
    {
        $event = $this->createEvent('Deactivated tracker event', '2032-06-15 18:00:00', '2032-06-15 20:00:00');
        $tracker = new TrackerService($this->getDatabase());
        $trackerUuid = $tracker->create(self::ORGANIZATION_ID, 1, 'Deactivated tracker', '', array(), array($event['id']), null);

        $tracker->deactivate(self::ORGANIZATION_ID, $trackerUuid, 1);

        $occurrences = (new TrackerRepository($this->getDatabase()))->findActiveOccurrences(self::ORGANIZATION_ID);

        $this->assertNotContains($trackerUuid, array_column($occurrences, 'eat_uuid'));
    }

    public function testTrackerCannotBeCreatedForPastEvent(): void
    {
        $event = $this->createEvent('Past tracker event', '2020-06-15 18:00:00', '2020-06-15 20:00:00');

        $this->expectException(\InvalidArgumentException::class);
        (new TrackerService($this->getDatabase()))->create(
            self::ORGANIZATION_ID,
            1,
            'Past event tracker',
            '',
            [],
            [$event['id']],
            null
        );
    }

    public function testSpreadsheetFormulaPrefixesAreEscaped(): void
    {
        foreach (array('=1+1', '+1+1', '-1+1', '@SUM(A1:A2)') as $value) {
            $this->assertSame("'" . $value, SpreadsheetValueEscaper::escapeFormula($value));
        }

        $this->assertSame('Regular name', SpreadsheetValueEscaper::escapeFormula('Regular name'));
    }

    public function testAutomationClosesDueAttendanceAndStoresLastRun(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $member = $fixture->createAndSaveUser('automation-member', 'automation-member@example.test');
        $role = $fixture->createAndSaveRole('Automation role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($member['usr_id'], $role['rol_id']);
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_MEMBERS . ' SET mem_begin = ?, mem_end = ? WHERE mem_rol_id = ? AND mem_usr_id = ?',
            array('2020-01-01', '9999-12-31', $role['rol_id'], $member['usr_id'])
        );
        $event = $this->createEvent('Automation event', '2032-06-15 18:00:00', '2032-06-15 20:00:00');
        $trackerId = $this->createTracker('Automation tracker', array($role['rol_id']), array($event['id']));
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_EVENTS . ' SET dat_begin = ?, dat_end = ? WHERE dat_id = ?',
            array('2020-06-15 18:00:00', '2020-06-15 20:00:00', $event['id'])
        );

        $automation = new AttendanceAutomationService($this->getDatabase());
        $automation->runForOrganization(self::ORGANIZATION_ID);

        $status = $this->getDatabase()->queryPrepared(
            'SELECT ear_status FROM ' . TABLE_PREFIX . '_event_attendance_records
              INNER JOIN ' . TABLE_PREFIX . '_event_attendance_events ON eae_id = ear_tracker_event_id
             WHERE eae_tracker_id = ? AND ear_usr_id = ?',
            array($trackerId, $member['usr_id'])
        )->fetchColumn();
        $this->assertSame('absent', $status);
        $this->assertNotNull($automation->getLastRun(self::ORGANIZATION_ID));
        $schedulerOrganizationId = (int) $this->getDatabase()->queryPrepared(
            'SELECT eas_org_id FROM ' . TABLE_PREFIX . '_event_attendance_scheduler WHERE eas_name = ?',
            array('close-due-events')
        )->fetchColumn();
        $this->assertSame(self::ORGANIZATION_ID, $schedulerOrganizationId);
    }

    public function testSnapshotServiceCanCloseDueEventsOutsideSchedulerTransaction(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $member = $fixture->createAndSaveUser('direct-snapshot-member', 'direct-snapshot-member@example.test');
        $role = $fixture->createAndSaveRole('Direct snapshot role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($member['usr_id'], $role['rol_id']);
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_MEMBERS . ' SET mem_begin = ?, mem_end = ? WHERE mem_rol_id = ? AND mem_usr_id = ?',
            array('2020-01-01', '9999-12-31', $role['rol_id'], $member['usr_id'])
        );
        $event = $this->createEvent('Direct snapshot event', '2032-07-15 18:00:00', '2032-07-15 20:00:00');
        $trackerId = $this->createTracker('Direct snapshot tracker', array($role['rol_id']), array($event['id']));
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_EVENTS . ' SET dat_begin = ?, dat_end = ? WHERE dat_id = ?',
            array('2020-07-15 18:00:00', '2020-07-15 20:00:00', $event['id'])
        );

        (new AttendanceSnapshotService($this->getDatabase()))->closeDueEvents(
            new \DateTimeImmutable('2020-07-15 21:00:00'),
            self::ORGANIZATION_ID
        );

        $status = $this->getDatabase()->queryPrepared(
            'SELECT ear_status FROM ' . TABLE_PREFIX . '_event_attendance_records
              INNER JOIN ' . TABLE_PREFIX . '_event_attendance_events ON eae_id = ear_tracker_event_id
             WHERE eae_tracker_id = ? AND ear_usr_id = ?',
            array($trackerId, $member['usr_id'])
        )->fetchColumn();
        $this->assertSame('absent', $status);
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
            null
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
