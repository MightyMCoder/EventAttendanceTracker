<?php

declare(strict_types=1);

use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;

if (isset($_GET['organization']) && preg_match('/^[A-Za-z0-9_-]{1,50}$/', (string) $_GET['organization']) === 1) {
    $g_organization = (string) $_GET['organization'];
}

require_once __DIR__ . '/../../../system/common.php';
require_once dirname(__DIR__) . '/autoload.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

try {
    $settings = LegacyPluginContext::initialize(dirname(__DIR__))->settings();
    $token = (string) ($_GET['token'] ?? '');
    $configuredToken = (string) ($settings['event_attendance_webcron_token'] ?? '');

    if ($configuredToken === '' || $token === '' || !hash_equals($configuredToken, $token)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error'));
        exit;
    }

    if (($settings['event_attendance_automation_method'] ?? 'disabled') !== 'webcron') {
        echo json_encode(array('status' => 'disabled'));
        exit;
    }

    $automation = new AttendanceAutomationService($gDb);
    $automation->runForOrganization((int) $gCurrentOrgId);
    echo json_encode(array('status' => 'success', 'last_run_at' => $automation->getLastRun((int) $gCurrentOrgId)));
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(array('status' => 'error'));
}
