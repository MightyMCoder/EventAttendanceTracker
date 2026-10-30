<?php
/** Run with: php tests/permanent_excuses.php (requires Admidio and PDO SQLite). */

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use AdmidioPlugin\EventAttendance\Service\AttendanceService;
use AdmidioPlugin\EventAttendance\Service\AttendanceSnapshotService;
use AdmidioPlugin\EventAttendance\Service\PermanentExcuseService;
use AdmidioPlugin\EventAttendance\ValueObject\AttendanceData;

date_default_timezone_set('UTC');
define('TABLE_PREFIX', 'test');
define('TBL_EVENTS', 'test_dates');
define('TBL_MEMBERS', 'test_members');
define('TBL_USER_DATA', 'test_user_data');
define('DATETIME_NOW', gmdate('Y-m-d H:i:s'));

$core = dirname(__DIR__, 3);
require $core . '/src/Infrastructure/Database.php';
require $core . '/src/ProfileFields/ValueObjects/ProfileFields.php';
require dirname(__DIR__) . '/src/Service/PermanentExcuseService.php';
require dirname(__DIR__) . '/src/Service/AttendanceSnapshotService.php';
require dirname(__DIR__) . '/src/Service/AttendanceService.php';
require dirname(__DIR__) . '/src/ValueObject/AttendanceData.php';

final class ExcuseTestDatabase extends Database
{
    public PDO $connection;
    private int $testDepth = 0;
    public function __construct()
    {
        $this->connection = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    }
    public function queryPrepared(string $sql, array $params = array(), bool $showError = true)
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
    public function startTransaction(): bool { return $this->testDepth++ > 0 || $this->connection->beginTransaction(); }
    public function endTransaction(): bool { return --$this->testDepth > 0 || $this->connection->commit(); }
    public function rollback(): bool
    {
        if ($this->testDepth === 0) { return false; }
        $this->testDepth = 0;
        return $this->connection->rollBack();
    }
    public function lastInsertId(): int { return (int) $this->connection->lastInsertId(); }
}

final class ExcuseTestFields extends ProfileFields
{
    public function __construct() {}
    public function getProperty(string $fieldNameIntern, string $column, string $format = '', bool $withObsoleteEntries = true): mixed
    {
        return $fieldNameIntern === 'FIRST_NAME' ? 1 : 2;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new ExcuseTestDatabase();
foreach (array(
    'CREATE TABLE test_event_attendance_trackers (eat_id INTEGER PRIMARY KEY, eat_org_id INTEGER, eat_uuid TEXT, eat_name TEXT, eat_active INTEGER, eat_cat_id INTEGER, eat_source_start TEXT)',
    'CREATE TABLE test_event_attendance_tracker_roles (etr_tracker_id INTEGER, etr_rol_id INTEGER)',
    'CREATE TABLE test_event_attendance_tracker_events (ete_tracker_id INTEGER, ete_dat_id INTEGER)',
    'CREATE TABLE test_dates (dat_id INTEGER PRIMARY KEY, dat_uuid TEXT, dat_headline TEXT, dat_begin TEXT, dat_end TEXT, dat_cat_id INTEGER)',
    'CREATE TABLE test_members (mem_rol_id INTEGER, mem_usr_id INTEGER, mem_begin TEXT, mem_end TEXT, mem_approved INTEGER)',
    'CREATE TABLE test_user_data (usd_usr_id INTEGER, usd_usf_id INTEGER, usd_value TEXT)',
    'CREATE TABLE test_event_attendance_events (eae_id INTEGER PRIMARY KEY, eae_tracker_id INTEGER, eae_dat_id INTEGER, eae_snapshot_at TEXT, eae_cancelled INTEGER DEFAULT 0)',
    'CREATE TABLE test_event_attendance_expected_users (eau_tracker_event_id INTEGER, eau_usr_id INTEGER, UNIQUE(eau_tracker_event_id, eau_usr_id))',
    'CREATE TABLE test_event_attendance_records (ear_id INTEGER PRIMARY KEY, ear_tracker_event_id INTEGER, ear_usr_id INTEGER, ear_status TEXT, ear_usr_id_create INTEGER, ear_usr_id_change INTEGER, ear_timestamp_change TEXT, UNIQUE(ear_tracker_event_id, ear_usr_id))',
) as $sql) {
    $db->queryPrepared($sql);
}
$migration = simplexml_load_file(dirname(__DIR__) . '/db_scripts/update_1_2.xml');
foreach ($migration->step as $step) {
    // Execute the migration in isolation, adapting only MySQL's integer syntax.
    $db->queryPrepared(str_replace(array('%PREFIX%', 'integer unsigned', 'AUTO_INCREMENT'), array('test', 'INTEGER', ''), (string) $step));
}
$db->queryPrepared("INSERT INTO test_event_attendance_trackers VALUES (1, 1, 'tracker1', 'First', 1, NULL, NULL), (2, 1, 'tracker2', 'Second', 1, NULL, NULL)");
$db->queryPrepared('INSERT INTO test_event_attendance_tracker_roles VALUES (1, 1), (2, 1)');
$db->queryPrepared("INSERT INTO test_members VALUES (1, 10, '2000-01-01', '9999-12-31', 0), (1, 11, '2000-01-01', '9999-12-31', 0)");
$now = new DateTimeImmutable(DATETIME_NOW);
foreach (array(1 => '-2 hours', 2 => '+2 hours', 3 => '+2 days') as $id => $offset) {
    $begin = $now->modify($offset);
    $db->queryPrepared('INSERT INTO test_dates VALUES (?, ?, ?, ?, ?, NULL)', array($id, 'event' . $id, 'Event', $begin->format('Y-m-d H:i:s'), $begin->modify('+1 hour')->format('Y-m-d H:i:s')));
    $db->queryPrepared('INSERT INTO test_event_attendance_tracker_events VALUES (1, ?), (2, ?)', array($id, $id));
}
$rules = new PermanentExcuseService($db);
$data = new AttendanceData($db);
$fields = new ExcuseTestFields();
$status = static function (int $tracker, string $event, int $user = 10) use ($data, $fields): ?string {
    foreach ($data->findRoster($tracker, $event, $fields) as $row) {
        if ((int) $row['eau_usr_id'] === $user) {
            return $row['ear_status'];
        }
    }
    throw new RuntimeException('Missing expected user.');
};
$rules->add(1, 10, $now->format('Y-m-d'), null);
check($status(1, 'event2') === 'excused', 'Expected default excuse.');
check($status(2, 'event2') === null, 'Rule leaked to another configuration.');
check($status(1, 'event2', 11) === null, 'Rule leaked to another person.');
check($status(1, 'event1') === null, 'Rule changed a past event.');
check($status(1, 'event3') === 'excused', 'Indefinite rule did not cover later event.');
check($data->findSummary(1, $fields)[0]['excused_count'] == 2, 'Report did not include virtual excuses.');
check($data->findSelfExcusableEvents(1, 10)[0]['ear_status'] === 'excused', 'Self-excuse view missed default.');

$originalId = (int) $rules->findAll(1)[0]['eax_id'];
$editedId = $rules->update(1, $originalId, 10, $now->modify('+2 days')->format('Y-m-d'), null);
check($status(1, 'event2') === null, 'Editing did not change future validity.');
check($status(1, 'event3') === 'excused', 'Edited rule did not cover its new period.');
check(count($rules->findAll(1)) === 1, 'Editing left duplicate active rules.');
try {
    $rules->update(2, $editedId, 10, $now->format('Y-m-d'), null);
    throw new RuntimeException('Rule from another configuration was edited.');
} catch (InvalidArgumentException $expected) {
}
try {
    $rules->update(1, $editedId, 10, '2026-02-30', null);
    throw new RuntimeException('Invalid edit was accepted.');
} catch (InvalidArgumentException $expected) {
}
check((int) $rules->findAll(1)[0]['eax_id'] === $editedId, 'Failed edit removed the original rule.');
$rules->update(1, $editedId, 10, $now->format('Y-m-d'), null);

$attendance = new AttendanceService($db);
$attendance->save(1, 'event2', 99, array(10 => 'present'));
check($status(1, 'event2') === 'present', 'Default overrode manual attendance.');
$attendance->save(1, 'event2', 99, array(10 => ''));
check($status(1, 'event2') === null, 'Explicit clearing did not override default.');
$attendance->save(1, 'event2', 99, array(10 => 'absent'));
check($status(1, 'event2') === 'absent', 'Default overrode manual absence.');
$rules->end(1, (int) $rules->findAll(1)[0]['eax_id']);
check($status(1, 'event3') === null, 'Ended rule still applied to future event.');
check($status(1, 'event2') === 'absent', 'Ending rule changed manual entry.');

$rules->add(1, 10, $now->modify('+2 days')->format('Y-m-d'), $now->modify('+2 days')->format('Y-m-d'));
check($status(1, 'event3') === 'excused', 'Inclusive end date failed.');
(new AttendanceSnapshotService($db))->closeDueEvents($now->modify('+4 days'), 1);
check($status(1, 'event3') === 'excused', 'Automation replaced default excuse.');
check($status(1, 'event3', 11) === 'absent', 'Automation did not close missing attendance.');
check($status(1, 'event2') === 'absent', 'Automation overrode manual status.');
$rules->end(1, (int) $rules->findAll(1)[0]['eax_id']);
check($status(1, 'event3') === 'excused', 'Ending rule changed frozen attendance.');

// A rule that applied before it was ended also remains visible without a cron run.
$db->queryPrepared("INSERT INTO test_event_attendance_excuses (eax_tracker_id, eax_usr_id, eax_date_from, eax_created_at, eax_revoked_at)
    VALUES (2, 10, '2000-01-01', ?, ?)", array($now->modify('-1 day')->format('Y-m-d H:i:s'), DATETIME_NOW));
check($status(2, 'event1') === 'absent', 'Historical rule overrode existing automatic record.');
$db->queryPrepared('DELETE FROM test_event_attendance_records WHERE ear_usr_id = 10 AND ear_tracker_event_id IN (SELECT eae_id FROM test_event_attendance_events WHERE eae_tracker_id = 2 AND eae_dat_id = 1)');
check($status(2, 'event1') === 'excused', 'Ended rule lost historical validity.');
check($status(2, 'event2') === 'absent', 'Ended rule changed future saved attendance.');
try {
    $rules->add(1, 999, $now->format('Y-m-d'), null);
    throw new RuntimeException('Unexpected person accepted.');
} catch (InvalidArgumentException $expected) {
}
try {
    $rules->add(1, 10, '2026-02-30', null);
    throw new RuntimeException('Invalid date accepted.');
} catch (InvalidArgumentException $expected) {
}
try {
    $rules->add(1, 10, '2030-02-01', '2030-01-01');
    throw new RuntimeException('Reversed date range accepted.');
} catch (InvalidArgumentException $expected) {
}
// Editing the table is persisted as one atomic list at final configuration save.
$rules->synchronize(2, array(array('id' => 0, 'user' => 11, 'from' => $now->format('Y-m-d'), 'until' => null)));
$savedRule = $rules->findAll(2)[0];
$rules->synchronize(2, array(array('id' => (int) $savedRule['eax_id'], 'user' => 11, 'from' => $now->format('Y-m-d'), 'until' => null)));
check((int) $rules->findAll(2)[0]['eax_id'] === (int) $savedRule['eax_id'], 'Unchanged rule was rewritten.');
try {
    $rules->synchronize(2, array(
        array('id' => 0, 'user' => 10, 'from' => $now->format('Y-m-d'), 'until' => null),
        array('id' => 0, 'user' => 999, 'from' => $now->format('Y-m-d'), 'until' => null)
    ));
    throw new RuntimeException('Invalid list was accepted.');
} catch (InvalidArgumentException $expected) {
}
check(count($rules->findAll(2)) === 1, 'Failed list save was not rolled back.');
$rules->synchronize(2, array());
check($rules->findAll(2) === array(), 'Staged deletion was not applied.');

// An ongoing event follows the current rule until its end, including after saving
// an attendance form with an untouched, prefilled default.
$db->queryPrepared('INSERT INTO test_dates VALUES (4, ?, ?, ?, ?, NULL)', array('event4', 'Ongoing', $now->modify('-30 minutes')->format('Y-m-d H:i:s'), $now->modify('+30 minutes')->format('Y-m-d H:i:s')));
$db->queryPrepared('INSERT INTO test_event_attendance_tracker_events VALUES (1, 4)');
$ongoingRule = $rules->add(1, 11, $now->modify('-1 day')->format('Y-m-d'), null);
check($status(1, 'event4', 11) === 'excused', 'Rule added during event was not previewed.');
$previewForm = array('attendance_status_11' => 'excused', 'attendance_default_excuses' => '[11]', 'attendance_changed_users' => '[]');
$attendance->saveForm(1, 'event4', 99, $previewForm, $fields);
(new AttendanceSnapshotService($db))->closeDueEvents($now, 1);
$ongoingRoster = $data->findRoster(1, 'event4', $fields);
$person = array_values(array_filter($ongoingRoster, static fn (array $row): bool => (int) $row['eau_usr_id'] === 11))[0];
check($person['ear_id'] === null, 'Preview was persisted before event end.');
$rules->end(1, $ongoingRule);
check($status(1, 'event4', 11) === null, 'Ongoing event kept an obsolete default.');
$attendance->saveForm(1, 'event4', 99, $previewForm, $fields);
check($status(1, 'event4', 11) === null, 'Stale form persisted an obsolete preview.');
$manualForm = $previewForm;
$manualForm['attendance_status_11'] = 'present';
$manualForm['attendance_changed_users'] = '[11]';
$attendance->saveForm(1, 'event4', 99, $manualForm, $fields);
$ongoingRule = $rules->add(1, 11, $now->modify('-1 day')->format('Y-m-d'), null);
check($status(1, 'event4', 11) === 'present', 'Current default overrode manual entry.');
$db->queryPrepared('DELETE FROM test_event_attendance_records WHERE ear_usr_id = 11 AND ear_tracker_event_id IN (SELECT eae_id FROM test_event_attendance_events WHERE eae_tracker_id = 1 AND eae_dat_id = 4)');
(new AttendanceSnapshotService($db))->closeDueEvents($now->modify('+1 hour'), 1);
$rules->end(1, $ongoingRule);
check($status(1, 'event4', 11) === 'excused', 'Completed default was not retained.');
echo "Permanent-excuse integration checks passed.\n";
