<?php
/** Run with: php tests/tracker_creation_excuses.php (requires Admidio and PDO SQLite). */

use AdmidioPlugin\EventAttendance\Service\TrackerService;

require __DIR__ . '/permanent_excuses.php';
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

define('TBL_ROLES', 'test_roles');
define('TBL_CATEGORIES', 'test_categories');
$db->queryPrepared('CREATE TABLE test_categories (cat_id INTEGER PRIMARY KEY, cat_org_id INTEGER, cat_type TEXT)');
$db->queryPrepared('CREATE TABLE test_roles (rol_id INTEGER PRIMARY KEY, rol_cat_id INTEGER)');
$db->queryPrepared("INSERT INTO test_categories VALUES (1, 1, 'ROL'), (2, 1, 'EVT')");
$db->queryPrepared('INSERT INTO test_roles VALUES (1, 1)');
$db->queryPrepared('ALTER TABLE test_event_attendance_trackers ADD COLUMN eat_description TEXT');
$db->queryPrepared('ALTER TABLE test_event_attendance_trackers ADD COLUMN eat_usr_id_create INTEGER');
$values = array(
    'tracker_name' => 'Created with excuses', 'tracker_description' => '',
    'tracker_roles' => array(1), 'tracker_source' => 'calendar', 'tracker_calendar' => 2,
    'tracker_permanent_excuses' => json_encode(array(array('id' => 0, 'user' => 10, 'from' => $now->format('Y-m-d'), 'until' => null)))
);
$service = new TrackerService($db);
$service->saveForm(1, 99, 'save', $values);
$created = $db->queryPrepared('SELECT eat_id FROM test_event_attendance_trackers WHERE eat_name = ?', array($values['tracker_name']))->fetchColumn();
check($created !== false, 'Configuration was not created.');
$createdRules = $rules->findAll((int) $created);
check(count($createdRules) === 1 && (int) $createdRules[0]['eax_usr_id'] === 10, 'New configuration did not save its permanent excuse.');
check($createdRules[0]['eax_date_until'] === null, 'Unlimited excuse was not retained.');

$values['tracker_name'] = 'Invalid excuse must roll back';
$values['tracker_permanent_excuses'] = json_encode(array(array('id' => 0, 'user' => 999, 'from' => $now->format('Y-m-d'), 'until' => null)));
try {
    $service->saveForm(1, 99, 'save', $values);
    throw new RuntimeException('Person outside the configured roles was accepted.');
} catch (InvalidArgumentException $exception) {
    check(str_contains($exception->getMessage(), 'configured roles'), 'Unexpected validation error.');
}
check($db->queryPrepared('SELECT eat_id FROM test_event_attendance_trackers WHERE eat_name = ?', array($values['tracker_name']))->fetchColumn() === false, 'Failed excuse validation left a partial configuration.');
$values['tracker_name'] = 'Created without excuses';
$values['tracker_permanent_excuses'] = '[]';
$service->saveForm(1, 99, 'save', $values);
check($db->queryPrepared('SELECT eat_id FROM test_event_attendance_trackers WHERE eat_name = ?', array($values['tracker_name']))->fetchColumn() !== false, 'Empty excuse list prevented creation.');
echo "Configuration creation with permanent excuses and atomic rollback passed.\n";
