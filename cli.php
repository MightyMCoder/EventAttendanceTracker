<?php

declare(strict_types=1);

use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;

if (PHP_SAPI !== 'cli') {
    exit("This script may only be run from the command line.\n");
}

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--organization=')) {
        $organization = substr($argument, strlen('--organization='));
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $organization)) {
            exit("Invalid organization short name.\n");
        }

        $g_organization = $organization;
    }
}

// Admidio's web bootstrap expects these values. A CLI invocation has no HTTP request.
$_SERVER += array(
    'SERVER_PORT' => '80',
    'SERVER_NAME' => 'localhost',
    'REQUEST_URI' => '/',
    'REMOTE_ADDR' => '127.0.0.1'
);

require_once __DIR__ . '/../../system/common.php';
require_once __DIR__ . '/autoload.php';

$settings = LegacyPluginContext::initialize(__DIR__, $gDb, (int) $gCurrentOrgId)->settings();
if (($settings['event_attendance_automation_method'] ?? 'disabled') !== 'cli') {
    exit("The system-cron automation is disabled.\n");
}

(new AttendanceAutomationService($gDb))->runForOrganization((int) $gCurrentOrgId);
echo "Closed due event-attendance records.\n";
