<?php

use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;

if (PHP_SAPI !== 'cli') {
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../../system/common.php';
require_once __DIR__ . '/autoload.php';

$settings = LegacyPluginContext::initialize(__DIR__)->settings();
if (($settings['event_attendance_automation_method'] ?? 'disabled') !== 'cli') {
    exit("The system-cron automation is disabled.\n");
}

(new AttendanceAutomationService($gDb))->runForOrganization((int) $gCurrentOrgId);
echo "Closed due event-attendance records.\n";
