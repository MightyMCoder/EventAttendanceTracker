<?php
/**
 * Contains integration tests for Event Attendance Tracker workflows.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Tests\Integration;

use Admidio\Events\Entity\Event;
use Admidio\Components\Entity\ComponentUpdate;
use Admidio\Infrastructure\Database;
use Admidio\Tests\Support\AdmidioTestFixture;
use Admidio\Tests\Support\DatabaseTestCase;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;
use AdmidioPlugin\EventAttendance\Repository\TrackerRepository;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportService;
use AdmidioPlugin\EventAttendance\Service\AttendanceService;
use AdmidioPlugin\EventAttendance\Service\AttendanceSnapshotService;
use AdmidioPlugin\EventAttendance\Service\LegacySettingsMigrationService;
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
require_once dirname(__DIR__, 2) . '/src/Service/LegacySettingsMigrationService.php';
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

        self::$gDb->queryPrepared(
            'CREATE TABLE IF NOT EXISTS ' . TABLE_PREFIX . '_event_attendance_settings (
                eas_org_id integer unsigned NOT NULL,
                eas_name varchar(100) NOT NULL,
                eas_value text,
                PRIMARY KEY (eas_org_id, eas_name)
            )'
        );

        // A 1.x upgrade enters the native installer with the attendance tables already present.
        // Re-running the complete installation schema must preserve those tables and only ensure
        // the scheduler row exists.
        foreach (Database::getSqlStatementsFromSqlFile(dirname(__DIR__, 2) . '/db_scripts/install.sql') as $statement) {
            self::$gDb->queryPrepared($statement);
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

    /**
     * A member selected through more than one tracker role must occur once in the attendance roster.
     */
    public function testMultipleTrackerRolesCreateOneExpectedAttendee(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $member = $fixture->createAndSaveUser('multiple-tracker-roles', 'multiple-tracker-roles@example.test');
        $firstRole = $fixture->createAndSaveRole('First attendance role', self::ORGANIZATION_ID);
        $secondRole = $fixture->createAndSaveRole('Second attendance role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($member['usr_id'], $firstRole['rol_id']);
        $fixture->assignUserToRole($member['usr_id'], $secondRole['rol_id']);
        $event = $this->createEvent('Multiple roles event', '2030-10-15 18:00:00', '2030-10-15 20:00:00');

        $trackerId = $this->createTracker(
            'Multiple roles tracker',
            array($firstRole['rol_id'], $secondRole['rol_id']),
            array($event['id'])
        );
        (new AttendanceSnapshotService($this->getDatabase()))->snapshotDueEventsForTracker(
            $trackerId,
            new \DateTimeImmutable('2030-10-16 00:00:00')
        );

        $roster = (new AttendanceRepository($this->getDatabase()))->findRoster(
            $trackerId,
            $event['uuid'],
            $GLOBALS['gProfileFields']
        );

        $this->assertCount(1, $roster);
        $this->assertSame((int)$member['usr_id'], (int)$roster[0]['eau_usr_id']);
    }

    /**
     * A calendar tracker automatically includes the future occurrences of its selected calendar.
     */
    public function testCalendarTrackerIncludesFutureCalendarEvents(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $calendar = $fixture->createAndSaveCategory('Attendance calendar', 'EVT', self::ORGANIZATION_ID);
        $firstEvent = $this->createEvent('First calendar event', '2030-11-15 18:00:00', '2030-11-15 20:00:00', (int)$calendar['cat_id']);
        $secondEvent = $this->createEvent('Second calendar event', '2030-12-15 18:00:00', '2030-12-15 20:00:00', (int)$calendar['cat_id']);
        $otherEvent = $this->createEvent('Other calendar event', '2030-11-20 18:00:00', '2030-11-20 20:00:00');

        $trackerUuid = (new TrackerService($this->getDatabase()))->create(
            self::ORGANIZATION_ID,
            1,
            'Calendar tracker',
            '',
            array(),
            array(),
            (int)$calendar['cat_id']
        );
        $occurrences = (new TrackerRepository($this->getDatabase()))->findOccurrences($this->trackerId($trackerUuid), 'all');

        $this->assertEqualsCanonicalizing(
            array($firstEvent['uuid'], $secondEvent['uuid']),
            array_column($occurrences, 'dat_uuid')
        );
        $this->assertNotContains($otherEvent['uuid'], array_column($occurrences, 'dat_uuid'));
    }

    /**
     * A self-excuse can be applied to one tracker or to every matching tracker configuration.
     */
    public function testSelfExcuseCanTargetOneOrAllMatchingConfigurations(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $member = $fixture->createAndSaveUser('multi-self-excuse', 'multi-self-excuse@example.test');
        $firstRole = $fixture->createAndSaveRole('First self-excuse role', self::ORGANIZATION_ID);
        $secondRole = $fixture->createAndSaveRole('Second self-excuse role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($member['usr_id'], $firstRole['rol_id']);
        $fixture->assignUserToRole($member['usr_id'], $secondRole['rol_id']);
        $event = $this->createEvent('Multiple self-excuse event', '2030-12-20 18:00:00', '2030-12-20 20:00:00');
        $trackers = new TrackerService($this->getDatabase());
        $firstTrackerUuid = $trackers->create(self::ORGANIZATION_ID, 1, 'First self-excuse tracker', '', array($firstRole['rol_id']), array($event['id']), null);
        $trackers->create(self::ORGANIZATION_ID, 1, 'Second self-excuse tracker', '', array($secondRole['rol_id']), array($event['id']), null);
        $service = new AttendanceService($this->getDatabase());

        $additionalConfigurations = $service->setSelfExcuseForConfiguration(
            self::ORGANIZATION_ID,
            (int)$member['usr_id'],
            0,
            $firstTrackerUuid,
            $event['uuid'],
            true
        );
        $this->assertSame(1, $additionalConfigurations);
        $this->assertSame(1, $this->countSelfExcuseRecords($event['id'], (int)$member['usr_id']));

        $service->setSelfExcuseForEvent(self::ORGANIZATION_ID, (int)$member['usr_id'], 0, $event['uuid'], true);
        $this->assertSame(2, $this->countSelfExcuseRecords($event['id'], (int)$member['usr_id']));
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

    public function testFutureCancelledOccurrenceKeepsExpectedAttendeeSnapshot(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $member = $fixture->createAndSaveUser('cancelled-snapshot-member', 'cancelled-snapshot-member@example.test');
        $role = $fixture->createAndSaveRole('Cancelled snapshot role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($member['usr_id'], $role['rol_id']);
        $event = $this->createEvent('Cancelled snapshot event', '2031-08-15 18:00:00', '2031-08-15 20:00:00');
        $trackerId = $this->createTracker('Cancelled snapshot tracker', array($role['rol_id']), array($event['id']));

        $this->getDatabase()->startTransaction();
        try {
            (new AttendanceSnapshotService($this->getDatabase()))->snapshotEventWithinTransaction(
                $trackerId,
                $event['id'],
                new \DateTimeImmutable('2030-01-01 00:00:00')
            );
            $this->getDatabase()->endTransaction();
        } catch (\Throwable $exception) {
            $this->getDatabase()->rollback();
            throw $exception;
        }

        $expectedCount = (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . TABLE_PREFIX . '_event_attendance_expected_users
              INNER JOIN ' . TABLE_PREFIX . '_event_attendance_events ON eae_id = eau_tracker_event_id
             WHERE eae_tracker_id = ? AND eae_dat_id = ? AND eae_snapshot_at IS NOT NULL',
            array($trackerId, $event['id'])
        )->fetchColumn();

        $this->assertSame(1, $expectedCount);
    }

    public function testSelfExcuseRejectsUnavailableEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AttendanceService($this->getDatabase()))->setSelfExcuseForEvent(
            self::ORGANIZATION_ID,
            1,
            0,
            '00000000-0000-0000-0000-000000000000',
            true
        );
    }

    public function testCancelledOccurrenceCanBeRestored(): void
    {
        $event = $this->createEvent('Restored attendance event', '2031-08-15 18:00:00', '2031-08-15 20:00:00');
        $trackerId = $this->createTracker('Restore cancelled occurrence', array(), array($event['id']));
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_events (eae_tracker_id, eae_dat_id, eae_cancelled) VALUES (?, ?, true)',
            array($trackerId, $event['id'])
        );

        (new AttendanceService($this->getDatabase()))->restore($trackerId, $event['uuid']);

        $this->assertSame(0, (int) $this->getDatabase()->queryPrepared(
            'SELECT eae_cancelled FROM ' . TABLE_PREFIX . '_event_attendance_events WHERE eae_tracker_id = ? AND eae_dat_id = ?',
            array($trackerId, $event['id'])
        )->fetchColumn());
    }

    public function testDeletingTrackerRemovesOnlyItsDependentAttendanceData(): void
    {
        $event = $this->createEvent('Tracker deletion event', '2031-09-15 18:00:00', '2031-09-15 20:00:00');
        $trackerService = new TrackerService($this->getDatabase());
        $trackerUuid = $trackerService->create(
            self::ORGANIZATION_ID,
            1,
            'Tracker to delete',
            '',
            array(),
            array($event['id']),
            null
        );
        $trackerId = $this->trackerId($trackerUuid);
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_events (eae_tracker_id, eae_dat_id) VALUES (?, ?)',
            array($trackerId, $event['id'])
        );
        $trackerEventId = (int) $this->getDatabase()->lastInsertId();
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_expected_users (eau_tracker_event_id, eau_usr_id) VALUES (?, ?)',
            array($trackerEventId, 1)
        );
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_records (ear_tracker_event_id, ear_usr_id, ear_status) VALUES (?, ?, ?)',
            array($trackerEventId, 1, 'present')
        );

        $trackerService->delete(self::ORGANIZATION_ID, $trackerUuid);

        $this->assertSame(0, (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . TABLE_PREFIX . '_event_attendance_trackers WHERE eat_id = ?',
            array($trackerId)
        )->fetchColumn());
        $this->assertSame(0, (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . TABLE_PREFIX . '_event_attendance_events WHERE eae_tracker_id = ?',
            array($trackerId)
        )->fetchColumn());
        $this->assertSame(1, (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . TBL_EVENTS . ' WHERE dat_id = ?',
            array($event['id'])
        )->fetchColumn());
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

    public function testReportPeriodUsesEventBeginDate(): void
    {
        $fixture = new AdmidioTestFixture($this->getDatabase());
        $member = $fixture->createAndSaveUser('report-period-member', 'report-period-member@example.test');
        $role = $fixture->createAndSaveRole('Report period role', self::ORGANIZATION_ID);
        $fixture->assignUserToRole($member['usr_id'], $role['rol_id']);
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_MEMBERS . ' SET mem_begin = ?, mem_end = ? WHERE mem_rol_id = ? AND mem_usr_id = ?',
            array('2020-01-01', '9999-12-31', $role['rol_id'], $member['usr_id'])
        );
        $firstEvent = $this->createEvent('First report period event', '2030-06-15 18:00:00', '2030-06-15 20:00:00');
        $secondEvent = $this->createEvent('Second report period event', '2031-06-15 18:00:00', '2031-06-15 20:00:00');
        $trackerId = $this->createTracker('Report period tracker', array($role['rol_id']), array($firstEvent['id'], $secondEvent['id']));
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_EVENTS . ' SET dat_begin = ?, dat_end = ? WHERE dat_id = ?',
            array('2020-06-15 18:00:00', '2020-06-15 20:00:00', $firstEvent['id'])
        );

        $summary = (new AttendanceRepository($this->getDatabase()))->findSummary(
            $trackerId,
            $GLOBALS['gProfileFields'],
            false,
            '2031-01-01',
            '2031-12-31'
        );
        $occurrences = (new TrackerRepository($this->getDatabase()))->findOccurrences(
            $trackerId,
            'all',
            '2031-01-01',
            '2031-12-31'
        );
        $pastOccurrences = (new TrackerRepository($this->getDatabase()))->findOccurrences($trackerId, 'past');
        $futureOccurrences = (new TrackerRepository($this->getDatabase()))->findOccurrences($trackerId, 'future');

        $this->assertCount(1, $summary);
        $this->assertSame(1, (int) $summary[0]['expected_count']);
        $this->assertCount(1, $occurrences);
        $this->assertSame($secondEvent['uuid'], $occurrences[0]['dat_uuid']);
        $this->assertSame(array($firstEvent['uuid']), array_column($pastOccurrences, 'dat_uuid'));
        $this->assertSame(array($secondEvent['uuid']), array_column($futureOccurrences, 'dat_uuid'));
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

    public function testLegacySettingsAreMigratedOnlyOnce(): void
    {
        $legacySettingsTable = TABLE_PREFIX . '_event_attendance_settings';
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . $legacySettingsTable . ' (eas_org_id, eas_name, eas_value) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)',
            array(
                self::ORGANIZATION_ID, 'event_attendance_members_may_excuse_themselves', 'true',
                self::ORGANIZATION_ID, 'event_attendance_self_excuse_roles', '[17,23]',
                self::ORGANIZATION_ID, 'event_attendance_export_filename', '"PLG_EVENT_ATTENDANCE_TRACKER_NAME"'
            )
        );

        LegacySettingsMigrationService::migrate();

        $settings = $this->getDatabase()->queryPrepared(
            'SELECT prf_name, prf_value
               FROM ' . TBL_PREFERENCES . '
              WHERE prf_org_id = ?
                AND prf_name IN (?, ?, ?)',
            array(
                self::ORGANIZATION_ID,
                'event_attendance_members_may_excuse_themselves',
                'event_attendance_self_excuse_roles',
                'event_attendance_export_filename'
            )
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertSame('1', $settings['event_attendance_members_may_excuse_themselves']);
        $this->assertSame('17,23', $settings['event_attendance_self_excuse_roles']);
        $this->assertSame('PLG_EVENT_ATTENDANCE_TRACKER_NAME', $settings['event_attendance_export_filename']);

        $this->getDatabase()->queryPrepared(
            'UPDATE ' . $legacySettingsTable . ' SET eas_value = ? WHERE eas_org_id = ? AND eas_name = ?',
            array('false', self::ORGANIZATION_ID, 'event_attendance_members_may_excuse_themselves')
        );
        LegacySettingsMigrationService::migrate();

        $value = $this->getDatabase()->queryPrepared(
            'SELECT prf_value FROM ' . TBL_PREFERENCES . ' WHERE prf_org_id = ? AND prf_name = ?',
            array(self::ORGANIZATION_ID, 'event_attendance_members_may_excuse_themselves')
        )->fetchColumn();
        $this->assertSame('1', $value);
    }

    public function testV1PluginUpdatePreservesAttendanceData(): void
    {
        $event = $this->createEvent('Legacy upgrade event', '2031-10-15 18:00:00', '2031-10-15 20:00:00');
        $trackerId = $this->createTracker('Legacy upgrade tracker', array(), array($event['id']));
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_events (eae_tracker_id, eae_dat_id, eae_snapshot_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
            array($trackerId, $event['id'])
        );
        $trackerEventId = (int) $this->getDatabase()->lastInsertId();
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_expected_users (eau_tracker_event_id, eau_usr_id) VALUES (?, ?)',
            array($trackerEventId, 1)
        );
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_records (ear_tracker_event_id, ear_usr_id, ear_status) VALUES (?, ?, ?)',
            array($trackerEventId, 1, 'present')
        );

        $update = new ComponentUpdate($this->getDatabase());
        $update->setValue('com_type', 'PLUGIN');
        $update->setValue('com_name', 'Event Attendance Tracker');
        $update->setValue('com_name_intern', 'event-attendance');
        $update->setValue('com_version', '1.0.0');
        $update->save();
        $update->updatePlugin('2.0.0-beta.1', 'AdmidioPlugin\\EventAttendance\\InstallationUpdate\\');

        $record = $this->getDatabase()->queryPrepared(
            'SELECT ear_status
               FROM ' . TABLE_PREFIX . '_event_attendance_records
              WHERE ear_tracker_event_id = ? AND ear_usr_id = ?',
            array($trackerEventId, 1)
        )->fetchColumn();
        $version = $this->getDatabase()->queryPrepared(
            'SELECT com_version FROM ' . TBL_COMPONENTS . ' WHERE com_name_intern = ?',
            array('event-attendance')
        )->fetchColumn();

        $this->assertSame('present', $record);
        $this->assertSame('2.0.0-beta', $version);
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
    private function createEvent(string $headline, string $begin, string $end, ?int $categoryId = null): array
    {
        if ($categoryId === null) {
            $categoryId = (int) $this->getDatabase()->queryPrepared(
                'SELECT cat_id FROM ' . TBL_CATEGORIES . ' WHERE cat_type = ? AND cat_org_id = ? ORDER BY cat_id',
                ['EVT', self::ORGANIZATION_ID]
            )->fetchColumn();
        }
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

    private function countSelfExcuseRecords(int $eventId, int $userId): int
    {
        return (int)$this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . TABLE_PREFIX . '_event_attendance_records
              INNER JOIN ' . TABLE_PREFIX . '_event_attendance_events ON eae_id = ear_tracker_event_id
             WHERE eae_dat_id = ? AND ear_usr_id = ? AND ear_status = ?',
            array($eventId, $userId, 'excused')
        )->fetchColumn();
    }
}
