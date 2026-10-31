<?php
/**
 * Main HTTP entry point for the Event Attendance Tracker plugin.
 *
 * The script bootstraps the legacy Admidio plugin environment and dispatches every
 * supported request mode to its dedicated presenter or application service.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use Admidio\Users\Entity\User;
use AdmidioPlugin\EventAttendance\EventAttendance;
use AdmidioPlugin\EventAttendance\Presenter\AttendancePresenter;
use AdmidioPlugin\EventAttendance\Presenter\EventAttendancePagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\ExportPresenter;
use AdmidioPlugin\EventAttendance\Presenter\LegacySettingsPresenter;
use AdmidioPlugin\EventAttendance\Presenter\TrackerFormPresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\PluginDefaults;
use AdmidioPlugin\EventAttendance\ValueObject\AttendanceData;
use AdmidioPlugin\EventAttendance\ValueObject\TrackerData;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;
use AdmidioPlugin\EventAttendance\Service\AttendanceLockService;
use AdmidioPlugin\EventAttendance\Service\AttendanceService;
use AdmidioPlugin\EventAttendance\Service\ExportService;
use AdmidioPlugin\EventAttendance\Service\PluginService;
use AdmidioPlugin\EventAttendance\Service\TrackerService;

try {
    // Session restoration must already be able to autoload the stored TrackerForm.
    require_once __DIR__ . '/autoload.php';
    require_once __DIR__ . '/../../system/common.php';
    require __DIR__ . '/../../system/login_valid.php';
    // The language object is available only after Admidio has restored the session.
    $gL10n->addLanguageFolderPath(__DIR__ . '/languages');

    $plugin = LegacyPluginContext::initialize(__DIR__, $gDb, $gCurrentOrgId);
    $lifecycle = new PluginService($gDb, __DIR__);
    $defaults = PluginDefaults::settings();
    $mode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'manage', 'install', 'uninstall', 'update', 'config', 'new', 'save', 'edit', 'save_edit', 'activate', 'deactivate', 'delete', 'cancel', 'restore', 'events', 'attendance', 'attendance_lock_acquire', 'attendance_lock_heartbeat', 'clear_attendance_locks', 'save_attendance', 'report', 'reports', 'report_pdf', 'report_print', 'report_export', 'my_events', 'save_self_excuses', 'toggle_self_excuse', 'settings', 'save_settings', 'check_update')
    ));
    $pluginInstalled = $lifecycle->isInstalled();
    $updateRequired = $pluginInstalled && $lifecycle->needsUpdate($gCurrentOrgId, $defaults);
    if ($pluginInstalled && $gCurrentUser->isAdministrator()) {
        $lifecycle->synchronizeMenuEntry($gCurrentOrgId, $plugin->settings());
    }
    $settings = $plugin->settings();
    $selfExcuseDeadlineHours = max(0, (int) ($settings['event_attendance_self_excuse_deadline_hours'] ?? 0));
    $recordingLockDays = max(0, (int) ($settings['event_attendance_recording_lock_days'] ?? 0));
    $attendanceLockEnabled = (bool) ($settings['event_attendance_attendance_lock_enabled'] ?? false);
    $attendanceLockDurationMinutes = min(1440, max(1, (int) ($settings['event_attendance_attendance_lock_duration_minutes'] ?? 5)));
    $trackers = new TrackerData($gDb);
    $reportExportPresenter = new ExportPresenter($gDb, $trackers, $plugin, $gProfileFields, $settings, $gL10n);
    $reportExportService = new ExportService($reportExportPresenter);
    $attendanceService = new AttendanceService($gDb, $trackers);
    $attendanceLockService = new AttendanceLockService($gDb, $attendanceLockDurationMinutes * 60);

    $trackerUuid = isset($_GET['tracker_uuid']) ? admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid') : '';
    $eventUuid = isset($_GET['event_uuid']) ? admFuncVariableIsValid($_GET, 'event_uuid', 'uuid') : '';
    $eventPeriodFilter = admFuncVariableIsValid($_GET, 'event_period_filter', 'string', array('defaultValue' => 'future', 'validValues' => array('future', 'past', 'all')));
    // Carry the attendance form's caller explicitly through save and lock expiry.
    // Only known local views are accepted as return destinations.
    $attendanceReturnMode = admFuncVariableIsValid($_GET, 'return_to', 'string', array('defaultValue' => 'list', 'validValues' => array('list', 'events')));
    $attendanceReturnPeriod = admFuncVariableIsValid($_GET, 'return_period', 'string', array('defaultValue' => 'future', 'validValues' => array('future', 'past', 'all')));
    $attendanceReturnParameters = array('mode' => $attendanceReturnMode, 'event_period_filter' => $attendanceReturnPeriod);
    if ($attendanceReturnMode === 'events' && $trackerUuid !== '') {
        $attendanceReturnParameters['tracker_uuid'] = $trackerUuid;
    }
    $attendanceReturnUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), $attendanceReturnParameters);
    $reportPercentageOperator = admFuncVariableIsValid($_GET, 'report_percentage_operator', 'string', array('defaultValue' => 'all', 'validValues' => array('all', 'lt', 'gt')));
    $reportPercentageValue = admFuncVariableIsValid($_GET, 'report_percentage_value', 'float', array('defaultValue' => 0.0));
    if ($reportPercentageValue < 0.0 || $reportPercentageValue > 100.0) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }
    $parseReportDate = static function (string $parameter): ?string {
        global $gSettingsManager;

        if (!isset($_GET[$parameter]) || $_GET[$parameter] === '') {
            return null;
        }

        $value = admFuncVariableIsValid($_GET, $parameter, 'date');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            ?: DateTimeImmutable::createFromFormat('!' . $gSettingsManager->getString('system_date'), $value);
        if ($date === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        return $date->format('Y-m-d');
    };
    $resolveReport = static function () use ($trackers, $gCurrentOrgId, $trackerUuid, $parseReportDate): array {
        $reportDateFrom = $parseReportDate('report_date_from');
        $reportDateUntil = $parseReportDate('report_date_until');
        $reportTracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($reportTracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $reportPeriodDefaults = $trackers->getReportPeriodDefaults((int) $reportTracker['eat_id']);
        if (!array_key_exists('report_date_from', $_GET)) {
            $reportDateFrom = $reportPeriodDefaults['dateFrom'];
        }
        if (!array_key_exists('report_date_until', $_GET)) {
            $reportDateUntil = $reportPeriodDefaults['dateUntil'];
        }
        if ($reportDateFrom !== null && $reportDateUntil !== null && $reportDateFrom > $reportDateUntil) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        return array($reportTracker, $reportDateFrom, $reportDateUntil);
    };
    $ensurePluginReady = static function () use ($plugin, $pluginInstalled, $updateRequired): void {
        if (!$pluginInstalled || $updateRequired) {
            admRedirect(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'manage')));
        }
    };
    $ensureManager = static function () use ($ensurePluginReady): void {
        $ensurePluginReady();
        if (!TrackerService::mayManage()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    };
    $ensureAttendanceRecorder = static function () use ($ensurePluginReady): void {
        $ensurePluginReady();
        if (!TrackerService::mayRecordAttendance()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    };
    $ensureSelfExcuseAccess = static function () use ($ensurePluginReady): void {
        $ensurePluginReady();
        if (!TrackerService::mayExcuseSelf()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    };
    $createManagementPage = static function () use ($plugin, $lifecycle, $pluginInstalled, $gCurrentSession, $gL10n): PagePresenter {
        $page = EventAttendancePagePresenter::createManagement($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NAME'));
        if (!$pluginInstalled) {
            $result = admFuncVariableIsValid($_GET, 'result', 'string', array('defaultValue' => '', 'validValues' => array('', 'uninstall')));
            $page->assignSmartyVariable('availableVersion', EventAttendance::getVersionLabel());
            $page->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
            $page->assignSmartyVariable('installUrl', $plugin->getUrl('index.php') . '?mode=install');
            $page->assignSmartyVariable('resultMessage', $result === 'uninstall' ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL_SUCCESS') : '');
            $page->addHtmlByTemplate('plugin.event-attendance.installation.tpl');
        } else {
            $page->assignSmartyVariable('installedVersion', (string) ($plugin->settings()['event_attendance_schema_version'] ?? ''));
            $page->assignSmartyVariable('availableVersion', EventAttendance::getVersionLabel());
            EventAttendancePagePresenter::createUpdateForm($page, $plugin);
        }

        return $page;
    };
    $dashboardUrl = $plugin->getUrl('index.php');

    switch ($mode) {
    case 'manage':
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if ($pluginInstalled && !$updateRequired) {
            admRedirect(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'settings')));
        }
        $createManagementPage()->show();
        exit;

    case 'install':
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $lifecycle->install($gCurrentOrgId, $defaults);
        admRedirect(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'settings', 'result' => 'install')));

    case 'uninstall':
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $lifecycle->uninstall();
        admRedirect(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'manage', 'result' => 'uninstall')));

    case 'update':
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $form->validate($_POST);
        $lifecycle->update($gCurrentOrgId, $defaults);
        EventAttendancePagePresenter::sendJson(array(
            'status' => 'success',
            'message' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_SUCCESS'),
            'url' => SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'list'))
        ));
        exit;

    case 'settings':
        $ensurePluginReady();
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $settings = $lifecycle->getSettings($gCurrentOrgId, $defaults);
        $page = EventAttendancePagePresenter::createSettings($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS'));
        $page->assignSmartyVariable('installationSuccessful', admFuncVariableIsValid($_GET, 'result', 'string', array('defaultValue' => '', 'validValues' => array('', 'install'))) === 'install');
        $page->assignSmartyVariable('attendanceLockEnabled', (bool) ($settings['event_attendance_attendance_lock_enabled'] ?? false));
        $page->assignSmartyVariable('clearAttendanceLocksUrl', $plugin->getUrl('index.php') . '?mode=clear_attendance_locks');
        $automationLastRun = (new AttendanceAutomationService($gDb))->getLastRun((int) $gCurrentOrgId);
        LegacySettingsPresenter::create($page, $plugin, $settings, $automationLastRun);
        $page->assignSmartyVariable('installedVersion', EventAttendance::getVersionLabel());
        $page->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
        $page->assignSmartyVariable('uninstallUrl', $plugin->getUrl('index.php') . '?mode=uninstall');
        $page->assignSmartyVariable('checkUpdateUrl', SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'check_update')));
        $page->assignSmartyVariable('pluginDescription', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DESCRIPTION'));
        $page->addHtmlByTemplate('plugin.event-attendance.settings-management.tpl');
        $page->show();
        exit;

    case 'check_update':
        $ensurePluginReady();
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $page = PagePresenter::withHtmlIDAndHeadline('adm_event_attendance_update_check', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS'));
        $page->addTemplateFolder($plugin->templatesDirectory());
        $releaseCheck = LegacySettingsPresenter::createUpdateViewModel($lifecycle->getReleasedVersions(), EventAttendance::getVersionLabel(), $gL10n);
        header('Content-Type: application/json; charset=UTF-8');
        EventAttendancePagePresenter::sendJson(array('html' => LegacySettingsPresenter::renderUpdateCheck($page, $releaseCheck)));
        exit;

    case 'save_settings':
        $ensurePluginReady();
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        $settings = $lifecycle->getSettings($gCurrentOrgId, $defaults);
        $panel = admFuncVariableIsValid($_GET, 'panel', 'string', array('validValues' => array('access', 'automation', 'rules', 'locks', 'export')));
        switch ($panel) {
            case 'access':
                $settings['event_attendance_tracker_manager_roles'] = array_map('intval', $formValues['event_attendance_tracker_manager_roles'] ?? array());
                $settings['event_attendance_attendance_recorder_roles'] = array_map('intval', $formValues['event_attendance_attendance_recorder_roles'] ?? array());
                break;
            case 'automation':
                $settings['event_attendance_automation_method'] = in_array($formValues['event_attendance_automation_method'] ?? '', array('disabled', 'cli', 'webcron'), true) ? $formValues['event_attendance_automation_method'] : 'disabled';
                $settings['event_attendance_webcron_token'] = trim((string) ($formValues['event_attendance_webcron_token'] ?? ''));
                break;
            case 'rules':
                foreach (array('event_attendance_members_may_excuse_themselves', 'event_attendance_rate_counts_excused') as $name) {
                    $settings[$name] = (string) ($formValues[$name] ?? '0') === '1';
                }
                $settings['event_attendance_self_excuse_roles'] = array_map('intval', $formValues['event_attendance_self_excuse_roles'] ?? array());
                foreach (array('event_attendance_self_excuse_deadline_hours', 'event_attendance_rate_decimal_places', 'event_attendance_rate_red_threshold', 'event_attendance_rate_orange_threshold', 'event_attendance_recording_lock_days') as $name) {
                    $settings[$name] = max(0, (int) ($formValues[$name] ?? 0));
                }
                $settings['event_attendance_rate_decimal_places'] = min(4, $settings['event_attendance_rate_decimal_places']);
                $settings['event_attendance_rate_red_threshold'] = min(100, $settings['event_attendance_rate_red_threshold']);
                $settings['event_attendance_rate_orange_threshold'] = min(100, max($settings['event_attendance_rate_red_threshold'], $settings['event_attendance_rate_orange_threshold']));
                break;
            case 'locks':
                $settings['event_attendance_attendance_lock_enabled'] = (string) ($formValues['event_attendance_attendance_lock_enabled'] ?? '0') === '1';
                $settings['event_attendance_attendance_lock_duration_minutes'] = min(1440, max(1, (int) ($formValues['event_attendance_attendance_lock_duration_minutes'] ?? 5)));
                if (!$settings['event_attendance_attendance_lock_enabled']) {
                    $attendanceLockService->clearAll($gCurrentOrgId);
                }
                break;
            case 'export':
                $settings['event_attendance_pdf_logo_file'] = trim((string) ($formValues['event_attendance_pdf_logo_file'] ?? ''));
                $settings['event_attendance_export_filename'] = trim((string) ($formValues['event_attendance_export_filename'] ?? ''));
                break;
        }
        $lifecycle->saveSettings($gCurrentOrgId, $settings);
        $lifecycle->synchronizeMenuEntry($gCurrentOrgId, $settings);
        EventAttendancePagePresenter::sendJsonSuccess(
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'settings')),
            $gL10n->get('SYS_SAVE_DATA')
        );
        exit;

    case 'save_self_excuses':
        $ensureSelfExcuseAccess();
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        $attendanceService->saveSelfExcuses($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours, $formValues['self_excuse_events'] ?? array());
        EventAttendancePagePresenter::sendJsonSuccess(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'my_events')), $gL10n->get('SYS_SAVE_DATA'));
        exit;

    case 'toggle_self_excuse':
        $ensureSelfExcuseAccess();
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $eventUuid = admFuncVariableIsValid($_POST, 'event_uuid', 'uuid');
        $excuse = admFuncVariableIsValid($_POST, 'action', 'string', array('validValues' => array('excuse', 'withdraw'))) === 'excuse';
        $scope = admFuncVariableIsValid($_POST, 'scope', 'string', array('defaultValue' => 'single', 'validValues' => array('single', 'all')));
        $service = $attendanceService;
        if ($scope === 'all') {
            $service->setSelfExcuseForEvent($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours, $eventUuid, $excuse);
            EventAttendancePagePresenter::sendJsonSuccess();
            exit;
        }
        $trackerUuid = admFuncVariableIsValid($_POST, 'tracker_uuid', 'uuid');
        EventAttendancePagePresenter::sendJson(array('status' => 'success', 'additionalConfigurations' => $service->setSelfExcuseForConfiguration($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours, $trackerUuid, $eventUuid, $excuse)));
        exit;

    case 'save':
    case 'save_edit':
        $ensureManager();
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $trackerSource = admFuncVariableIsValid($_POST, 'tracker_source', 'string', array('validValues' => array('events', 'calendar')));
        $form->setElementProperty($trackerSource === 'calendar' ? 'tracker_events' : 'tracker_calendar', FormPresenter::FIELD_DISABLED);
        // Modal controls are client-side drafts; validate the complete list in the service.
        foreach (array('excuse_id', 'excuse_user', 'excuse_from', 'excuse_until') as $field) {
            $form->setElementProperty($field, FormPresenter::FIELD_DISABLED);
        }
        $formValues = $form->validate($_POST);
        (new TrackerService($gDb))->saveForm($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $mode, $formValues, $mode === 'save_edit' ? admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid') : null);
        EventAttendancePagePresenter::sendJson(array(
            'status' => 'success',
            'message' => $gL10n->get('SYS_SAVE_DATA'),
            'url' => SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config'))
        ));
        exit;

    case 'activate':
    case 'deactivate':
        $ensureManager();
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        (new TrackerService($gDb))->setActive($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'), $mode === 'activate');
        EventAttendancePagePresenter::sendJsonSuccess();
        exit;

    case 'delete':
        $ensureManager();
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        (new TrackerService($gDb))->delete($gCurrentOrgId, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'));
        EventAttendancePagePresenter::sendJsonSuccess();
        exit;

    case 'cancel':
    case 'restore':
        $ensureManager();
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $tracker = $trackers->findByUuid($gCurrentOrgId, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'));
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $attendanceService->assertTrackerIsActive($tracker);
        $eventUuid = admFuncVariableIsValid($_GET, 'event_uuid', 'uuid');
        $attendanceService->assertRecordingIsOpen($tracker, $eventUuid, $recordingLockDays);
        $attendanceActionService = new AttendanceService($gDb);
        if ($mode === 'cancel') {
            $attendanceActionService->cancel((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'));
        } else {
            $attendanceActionService->restore((int) $tracker['eat_id'], $eventUuid);
        }
        EventAttendancePagePresenter::sendJsonSuccess();
        exit;

    case 'save_attendance':
        $ensureAttendanceRecorder();
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        $lockToken = (string) ($formValues['attendance_lock_token'] ?? '');
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $attendanceService->assertTrackerIsActive($tracker);
        $attendanceService->assertRecordingIsOpen($tracker, $eventUuid, $recordingLockDays);
        if ($attendanceLockEnabled && !$attendanceLockService->refresh((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $lockToken)) {
            throw new Exception('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCK_LOST');
        }
        $attendanceActionService = $attendanceService;
        if (isset($_POST['adm_button_cancel_event'])) {
            $ensureManager();
            $attendanceActionService->cancel((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'));
            if ($attendanceLockEnabled) {
                $attendanceLockService->release((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $lockToken);
            }
            EventAttendancePagePresenter::sendJsonSuccess($attendanceReturnUrl, $gL10n->get('SYS_SAVE_DATA'));
            exit;
        }
        $attendanceActionService->saveForm((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $formValues, $gProfileFields);
        if ($attendanceLockEnabled) {
            $attendanceLockService->release((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $lockToken);
        }
        EventAttendancePagePresenter::sendJsonSuccess($attendanceReturnUrl, $gL10n->get('SYS_SAVE_DATA'));
        exit;

    case 'attendance_lock_heartbeat':
        if (!$attendanceLockEnabled) {
            EventAttendancePagePresenter::sendJson(array('status' => 'locked'));
            exit;
        }
        $ensureAttendanceRecorder();
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $attendanceService->assertTrackerIsActive($tracker);
        $attendanceService->assertRecordingIsOpen($tracker, $eventUuid, $recordingLockDays);
        $lockToken = (string) ($_POST['attendance_lock_token'] ?? '');
        EventAttendancePagePresenter::sendJson(array(
            'status' => $attendanceLockService->refresh((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $lockToken) ? 'success' : 'locked'
        ));
        exit;

    case 'attendance_lock_acquire':
        if (!$attendanceLockEnabled) {
            EventAttendancePagePresenter::sendJsonSuccess();
            exit;
        }
        $ensureAttendanceRecorder();
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $attendanceService->assertTrackerIsActive($tracker);
        $attendanceService->assertRecordingIsOpen($tracker, $eventUuid, $recordingLockDays);
        $lock = $attendanceLockService->acquire(
            (int) $tracker['eat_id'],
            $eventUuid,
            (int) $gCurrentUser->getValue('usr_id'),
            (string) $gCurrentSession->getValue('ses_session_id')
        );
        if ($lock['token'] === null) {
            $lockOwner = new User($gDb, $gProfileFields, (int) $lock['lockedUserId']);
            $lockOwnerName = trim($lockOwner->getValue('FIRST_NAME', 'html') . ' ' . $lockOwner->getValue('LAST_NAME', 'html'));
            EventAttendancePagePresenter::sendJson(array(
                'status' => 'locked',
                'message' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCKED', array($lockOwnerName))
            ));
            exit;
        }
        EventAttendancePagePresenter::sendJsonSuccess();
        exit;

    case 'clear_attendance_locks':
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $attendanceLockService->clearAll($gCurrentOrgId);
        EventAttendancePagePresenter::sendJsonSuccess();
        exit;

    case 'report_print':
        $ensureManager();
        list($reportTracker, $reportDateFrom, $reportDateUntil) = $resolveReport();
        $printPage = EventAttendancePagePresenter::createPrintReport($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $reportTracker['eat_name']);
        $printPage->addTemplateFile('plugin.event-attendance.report.tpl');
        $printPage->assignSmartyVariable('rows', $reportExportPresenter->getRows($reportTracker, $reportPercentageOperator, $reportPercentageValue, $reportDateFrom, $reportDateUntil));
        $printPage->show();
        exit;

    case 'report_export':
        $ensureManager();
        list($reportTracker, $reportDateFrom, $reportDateUntil) = $resolveReport();
        $format = admFuncVariableIsValid($_GET, 'format', 'string', array('validValues' => array('csv', 'xlsx')));
        $reportExportService->createExport($reportTracker, $format, $reportPercentageOperator, $reportPercentageValue, 'L', $reportDateFrom, $reportDateUntil);
        exit;

    case 'report_pdf':
        $ensureManager();
        list($reportTracker, $reportDateFrom, $reportDateUntil) = $resolveReport();
        $reportExportService->createExport($reportTracker, 'pdf', $reportPercentageOperator, $reportPercentageValue, admFuncVariableIsValid($_GET, 'orientation', 'string', array('defaultValue' => 'L', 'validValues' => array('P', 'L'))), $reportDateFrom, $reportDateUntil);
        exit;

    case 'new':
        $ensureManager();
        $page = EventAttendancePagePresenter::createTrackerEdit($plugin, $gL10n->get('SYS_CREATE'));
        (new TrackerFormPresenter())->create($page, $plugin);
        break;

    case 'edit':
        $ensureManager();
        $page = EventAttendancePagePresenter::createTrackerEdit($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE'));
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $attendanceService->assertTrackerIsActive($tracker);
        (new TrackerFormPresenter())->create($page, $plugin, $tracker, $trackers->findConfiguration((int) $tracker['eat_id']));
        break;

    case 'my_events':
        $ensureSelfExcuseAccess();
        $page = AttendancePresenter::createSelfExcusePage($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF'));
        AttendancePresenter::createSelfExcuseOverview($page, $plugin, (new AttendanceData($gDb))->findSelfExcusableEvents($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours));
        break;

    case 'attendance':
        $ensureAttendanceRecorder();
        $page = EventAttendancePagePresenter::createAttendance($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE'));
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $attendanceService->assertTrackerIsActive($tracker);
        $occurrences = $trackers->findOccurrences((int) $tracker['eat_id'], 'all');
        $occurrence = array_values(array_filter($occurrences, static fn (array $entry): bool => $entry['dat_uuid'] === $eventUuid))[0] ?? false;
        if ($occurrence === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $attendanceService->assertRecordingIsOpen($tracker, $eventUuid, $recordingLockDays);
        $page->setHeadline($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RECORD') . ': ' . $occurrence['dat_headline']);
        if ($attendanceReturnMode === 'events') {
            $page->addNavigation($attendanceReturnUrl, $tracker['eat_name']);
        }
        $page->addCurrentNavigation();
        $lockToken = null;
        if ($attendanceLockEnabled) {
            $lock = $attendanceLockService->acquire(
                (int) $tracker['eat_id'],
                $eventUuid,
                (int) $gCurrentUser->getValue('usr_id'),
                (string) $gCurrentSession->getValue('ses_session_id')
            );
            $lockToken = $lock['token'];
        }
        if ($attendanceLockEnabled && $lockToken === null) {
            $lockOwner = new User($gDb, $gProfileFields, (int) $lock['lockedUserId']);
            $lockOwnerName = trim($lockOwner->getValue('FIRST_NAME', 'html') . ' ' . $lockOwner->getValue('LAST_NAME', 'html'));
            $lockMessage = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCKED', array($lockOwnerName));
            $page->addJavascript(
                'messageBox(' . json_encode($lockMessage, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG)
                . ', ' . json_encode($gL10n->get('SYS_NOTE'), JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG) . ', "warning");',
                true
            );
            break;
        }
        (new AttendancePresenter())->create(
            $page,
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array(
                'mode' => 'save_attendance', 'tracker_uuid' => $trackerUuid, 'event_uuid' => $eventUuid,
                'return_to' => $attendanceReturnMode, 'return_period' => $attendanceReturnPeriod
            )),
            (new AttendanceData($gDb))->findRoster((int) $tracker['eat_id'], $eventUuid, $gProfileFields),
            $lockToken,
            $plugin->getUrl('index.php') . '?mode=attendance_lock_heartbeat&tracker_uuid=' . $trackerUuid . '&event_uuid=' . $eventUuid,
            $attendanceLockService->getHeartbeatIntervalSeconds(),
            $attendanceReturnUrl
        );
        break;

    case 'report':
        $ensureManager();
        list($reportTracker, $reportDateFrom, $reportDateUntil) = $resolveReport();
        $page = EventAttendancePagePresenter::createReport($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE'));
        $tracker = $reportTracker;
        $page->setHeadline($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name']);
        $page->addNavigation(SecurityUtils::encodeUrl($dashboardUrl, array('mode' => 'reports')), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORTS'));
        $page->addCurrentNavigation();
        $reportUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'report', 'tracker_uuid' => $trackerUuid));
        $filterForm = new FormPresenter('adm_event_attendance_report_filter_form', 'sys-template-parts/form.filter.tpl', $reportUrl, $page, array('type' => 'navbar', 'setFocus' => false));
        $filterForm->addSelectBox('report_percentage_operator', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_RATE'), array('all' => $gL10n->get('SYS_ALL'), 'lt' => '<', 'gt' => '>'), array('defaultValue' => $reportPercentageOperator, 'showContextDependentFirstEntry' => false));
        $filterForm->addInput('report_percentage_value', '%', (string) $reportPercentageValue, array('type' => 'text', 'class' => 'form-control-small'));
        $filterForm->addInput('report_date_from', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT_DATE_FROM'), $reportDateFrom ?? '', array('type' => 'date'));
        $filterForm->addInput('report_date_until', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT_DATE_UNTIL'), $reportDateUntil ?? '', array('type' => 'date'));
        $filterForm->addButton('adm_event_attendance_report_filter_reset', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RESET_FILTER'), array('icon' => 'bi-arrow-counterclockwise'));
        $filterForm->addToHtmlPage();
        $page->addJavascript('$("#report_percentage_operator, #report_percentage_value, #report_date_from, #report_date_until").on("change", function() {
            const url = new URL(window.location.href);
            url.searchParams.set("mode", "report");
            url.searchParams.set("tracker_uuid", ' . json_encode($trackerUuid) . ');
            url.searchParams.set("report_percentage_operator", $("#report_percentage_operator").val());
            url.searchParams.set("report_percentage_value", $("#report_percentage_value").val());
            url.searchParams.set("report_date_from", $("#report_date_from").val());
            url.searchParams.set("report_date_until", $("#report_date_until").val());
            window.location.assign(url.toString());
        });', true);
        $page->addJavascript('$("#adm_event_attendance_report_filter_reset").on("click", function() {
            window.location.assign(' . json_encode($reportUrl) . ');
        });', true);
        $page->addTemplateFile('plugin.event-attendance.report.tpl');
        $rows = $reportExportPresenter->getRows($tracker, $reportPercentageOperator, $reportPercentageValue, $reportDateFrom, $reportDateUntil);
        $page->assignSmartyVariable('rows', $rows);
        $exportParameters = array('tracker_uuid' => $trackerUuid, 'report_percentage_operator' => $reportPercentageOperator, 'report_percentage_value' => $reportPercentageValue);
        if ($reportDateFrom !== null || array_key_exists('report_date_from', $_GET)) {
            $exportParameters['report_date_from'] = $reportDateFrom ?? '';
        }
        if ($reportDateUntil !== null || array_key_exists('report_date_until', $_GET)) {
            $exportParameters['report_date_until'] = $reportDateUntil ?? '';
        }
        $page->addPageFunctionsMenuItem('adm_event_attendance_report_preview', $gL10n->get('SYS_PRINT_PREVIEW'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array_merge($exportParameters, array('mode' => 'report_print'))), 'bi-printer-fill');
        $page->addPageFunctionsMenuItem('adm_event_attendance_report_export', $gL10n->get('SYS_EXPORT'), '#', 'bi-download');
        $page->addPageFunctionsMenuItem('adm_event_attendance_report_pdf_portrait', $gL10n->get('SYS_PDF') . ' ' . $gL10n->get('SYS_PORTRAIT'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array_merge($exportParameters, array('mode' => 'report_pdf', 'orientation' => 'P'))), 'bi-filetype-pdf', 'adm_event_attendance_report_export');
        $page->addPageFunctionsMenuItem('adm_event_attendance_report_pdf_landscape', $gL10n->get('SYS_PDF') . ' ' . $gL10n->get('SYS_LANDSCAPE'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array_merge($exportParameters, array('mode' => 'report_pdf', 'orientation' => 'L'))), 'bi-filetype-pdf', 'adm_event_attendance_report_export');
        $page->addJavascript(
            '$("#adm_event_attendance_report_pdf_portrait, #adm_event_attendance_report_pdf_landscape").attr("download", '
            . json_encode($reportExportService->getBrowserFilename('pdf')) . ');',
            true
        );
        $page->addPageFunctionsMenuItem('adm_event_attendance_report_xlsx', $gL10n->get('SYS_MICROSOFT_EXCEL') . ' (*.xlsx)', SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array_merge($exportParameters, array('mode' => 'report_export', 'format' => 'xlsx'))), 'bi-file-earmark-excel-fill', 'adm_event_attendance_report_export');
        $page->addPageFunctionsMenuItem('adm_event_attendance_report_csv', $gL10n->get('SYS_CSV'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array_merge($exportParameters, array('mode' => 'report_export', 'format' => 'csv'))), 'bi-filetype-csv', 'adm_event_attendance_report_export');
        if (count($rows) > 0) {
            (new DataTables($page, 'adm_event_attendance_report_table'))->createJavascript(count($rows), 8);
        }
        break;

    case 'reports':
        $ensureManager();
        $page = EventAttendancePagePresenter::createReports($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORTS'));
        $page->addTemplateFile('plugin.event-attendance.reports.tpl');
        $allTrackers = $trackers->findAll($gCurrentOrgId);
        foreach ($allTrackers as &$tracker) {
            $tracker['reportUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'report', 'tracker_uuid' => $tracker['eat_uuid']));
        }
        unset($tracker);
        $page->assignSmartyVariable('trackers', $allTrackers);
        break;

    case 'events':
        $ensureAttendanceRecorder();
        $page = EventAttendancePagePresenter::createEvents($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE'));
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $page->setHeadline($tracker['eat_name']);
        $page->addCurrentNavigation();
        $filterForm = new FormPresenter('adm_event_attendance_event_filter_form', 'sys-template-parts/form.filter.tpl', '', $page, array('type' => 'navbar', 'setFocus' => false));
        $filterForm->addSelectBox('event_period_filter', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EVENT_PERIOD'), array('future' => 'PLG_EVENT_ATTENDANCE_TRACKER_EVENT_PERIOD_FUTURE', 'past' => 'PLG_EVENT_ATTENDANCE_TRACKER_EVENT_PERIOD_PAST', 'all' => 'SYS_ALL'), array('defaultValue' => $eventPeriodFilter, 'showContextDependentFirstEntry' => false));
        $filterForm->addToHtmlPage();
        $eventsUrl = json_encode(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'events', 'tracker_uuid' => $trackerUuid)));
        $page->addJavascript('$("#event_period_filter").on("change", function() { const url = new URL(' . $eventsUrl . ', window.location.origin); url.searchParams.set("event_period_filter", $(this).val()); window.location.assign(url.toString()); });', true);
        $page->addTemplateFile('plugin.event-attendance.events.tpl');
        $occurrences = $trackers->findOccurrences((int) $tracker['eat_id'], $eventPeriodFilter);
        foreach ($occurrences as &$occurrence) {
            $occurrence['url'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'attendance', 'tracker_uuid' => $trackerUuid, 'event_uuid' => $occurrence['dat_uuid'], 'return_to' => 'events', 'return_period' => $eventPeriodFilter));
        }
        unset($occurrence);
        $page->assignSmartyVariable('occurrences', $occurrences);
        if (count($occurrences) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_events_table');
            $dataTables->setOrderColumns(array(array(1, 'asc')));
            $dataTables->createJavascript(count($occurrences), 3);
        }
        break;

    case 'config':
        $ensureManager();
        $page = EventAttendancePagePresenter::createTrackerConfiguration($plugin, $gL10n->get('SYS_CONFIGURATION'));
        $page->addPageFunctionsMenuItem('adm_event_attendance_create_tracker', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ADD_TRACKER'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'new')), 'bi-plus-circle-fill');
        $filterForm = new FormPresenter('adm_event_attendance_tracker_filter_form', 'sys-template-parts/form.filter.tpl', '', $page, array('type' => 'navbar', 'setFocus' => false));
        $filterForm->addSelectBox('tracker_active_filter', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_STATUS'), array('all' => $gL10n->get('SYS_ALL'), 'active' => $gL10n->get('SYS_ENABLED'), 'inactive' => $gL10n->get('SYS_DISABLED')), array('defaultValue' => 'all', 'showContextDependentFirstEntry' => false));
        $filterForm->addToHtmlPage();
        $page->addTemplateFile('plugin.event-attendance.trackers.tpl');
        $allTrackers = $trackers->findAll($gCurrentOrgId);
        foreach ($allTrackers as &$tracker) {
            $tracker['editUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'edit', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['activateUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'activate', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['deactivateUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'deactivate', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['deleteUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'delete', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['activateMessage'] = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ACTIVATE_CONFIRM', array($tracker['eat_name']));
            $tracker['deactivateMessage'] = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DEACTIVATE_CONFIRM', array($tracker['eat_name']));
            $tracker['deleteMessage'] = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DELETE_CONFIRM', array($tracker['eat_name']));
            $tracker['uuid'] = $tracker['eat_uuid'];
            $tracker['source'] = (int) $tracker['eat_cat_id'] > 0
                ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_CALENDAR') . ': ' . $tracker['calendar_name']
                : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_EVENTS');
            if ($tracker['eat_active']) {
                $tracker['actions'] = array(
                    array('url' => $tracker['editUrl'], 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('SYS_EDIT')),
                );
                $tracker['actions'][] =
                    array('dataHref' => 'deactivateEventAttendanceTracker(\'adm_event_attendance_tracker_' . $tracker['eat_uuid'] . '\', \'' . $tracker['deactivateUrl'] . '\', \'' . $gCurrentSession->getCsrfToken() . '\');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DEACTIVATE_CONFIRM', array($tracker['eat_name'])), 'icon' => 'bi bi-slash-circle text-secondary', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DEACTIVATE'))
                ;
            } else {
                $tracker['actions'] = array();
                $tracker['actions'][] =
                    array('dataHref' => 'activateEventAttendanceTracker(\'adm_event_attendance_tracker_' . $tracker['eat_uuid'] . '\', \'' . $tracker['activateUrl'] . '\', \'' . $gCurrentSession->getCsrfToken() . '\');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ACTIVATE_CONFIRM', array($tracker['eat_name'])), 'icon' => 'bi bi-check-circle-fill text-success', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ACTIVATE'))
                ;
            }
            $tracker['actions'][] = array(
                'dataHref' => 'deleteEventAttendanceTracker(\'adm_event_attendance_tracker_' . $tracker['eat_uuid'] . '\', \'' . $tracker['deleteUrl'] . '\', \'' . $gCurrentSession->getCsrfToken() . '\');',
                'dataMessage' => $tracker['deleteMessage'],
                'icon' => 'bi bi-trash-fill text-danger',
                'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DELETE')
            );
        }
        unset($tracker);
        $page->assignSmartyVariable('trackers', $allTrackers);
        if (count($allTrackers) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_trackers_table');
            $dataTables->disableColumnsSort(array(5));
            $configurationUrl = json_encode(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config')));
            $page->addJavascript('
                $("#adm_event_attendance_trackers_table").one("init.dt", function() {
                    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                        if (settings.nTable.id !== "adm_event_attendance_trackers_table") {
                            return true;
                        }
                        const filter = $("#tracker_active_filter").val();
                        return filter === "all" || settings.aoData[dataIndex].nTr.dataset.status === filter;
                    });

                    $("#tracker_active_filter").on("change", function() {
                        $("#adm_event_attendance_trackers_table").DataTable().draw();
                    });
                    if ($("#tracker_active_filter").val() !== "all") {
                        $("#adm_event_attendance_trackers_table").DataTable().draw();
                    }
                });

                window.setEventAttendanceTrackerStatus = function(rowId, url, csrfToken, status) {
                    $.post(url, { adm_csrf_token: csrfToken }, function(response) {
                        const result = typeof response === "object" ? response : JSON.parse(response);
                        if (result.status !== "success") {
                            return;
                        }
                        $("#adm_modal_messagebox, #adm_modal").modal("hide");
                        window.location.href = ' . $configurationUrl . ';
                    });
                };
                window.deactivateEventAttendanceTracker = function(rowId, url, csrfToken) {
                    window.setEventAttendanceTrackerStatus(rowId, url, csrfToken, "inactive");
                };
                window.activateEventAttendanceTracker = function(rowId, url, csrfToken) {
                    window.setEventAttendanceTrackerStatus(rowId, url, csrfToken, "active");
                };
                window.deleteEventAttendanceTracker = function(rowId, url, csrfToken) {
                    window.setEventAttendanceTrackerStatus(rowId, url, csrfToken, "deleted");
                };
            ', true);
            $dataTables->createJavascript(count($allTrackers), 5);
        }
        break;

    case 'list':
        if (!$pluginInstalled || $updateRequired) {
            if (!$gCurrentUser->isAdministrator()) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $createManagementPage()->show();
            exit;
        }
        if (!TrackerService::mayRecordAttendance()) {
            if (TrackerService::mayExcuseSelf()) {
                $page = AttendancePresenter::createSelfExcusePage($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF'));
                AttendancePresenter::createSelfExcuseOverview($page, $plugin, (new AttendanceData($gDb))->findSelfExcusableEvents($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours));
                $page->show();
                exit;
            }
            throw new Exception('SYS_NO_RIGHTS');
        }
        $page = EventAttendancePagePresenter::createDashboard($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE'));
        $mayManage = TrackerService::mayManage();
        if ($gCurrentUser->isAdministrator()) {
            $settingsUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'settings'));
            $page->addPageFunctionsMenuItem('adm_event_attendance_plugin_settings', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS'), $settingsUrl, 'bi-gear-fill');
        }
        if ($mayManage) {
            $page->addPageFunctionsMenuItem('adm_event_attendance_configuration', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CONFIGURATIONS'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config')), 'bi-gear-fill');
            $page->addPageFunctionsMenuItem('adm_event_attendance_reports', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORTS'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'reports')), 'bi-bar-chart-fill');
        }
        if (TrackerService::mayExcuseSelf()) {
            $page->addPageFunctionsMenuItem('adm_event_attendance_self_excuse', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'my_events')), 'bi-person-x-fill');
        }
        $page->addTemplateFile('plugin.event-attendance.dashboard.tpl');
        $occurrences = $trackers->findActiveOccurrences($gCurrentOrgId, $eventPeriodFilter);
        $trackerFilterValues = array('all' => $gL10n->get('SYS_ALL'));
        $now = new DateTimeImmutable(DATETIME_NOW);
        foreach ($occurrences as &$occurrence) {
            $trackerFilterValues[$occurrence['eat_uuid']] = $occurrence['eat_name'];
            $occurrence['url'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'attendance', 'tracker_uuid' => $occurrence['eat_uuid'], 'event_uuid' => $occurrence['dat_uuid'], 'return_to' => 'list', 'return_period' => $eventPeriodFilter));
            $occurrence['trackerUuid'] = $occurrence['eat_uuid'];
            $occurrence['uuid'] = $occurrence['eat_uuid'] . '_' . $occurrence['dat_uuid'];
            $occurrence['status'] = $occurrence['cancelled']
                ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED')
                : ($occurrence['eae_snapshot_at'] !== null && new DateTimeImmutable($occurrence['dat_end']) <= $now
                    ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RECORDED')
                    : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DASHBOARD_STATUS_OPEN'));
            $occurrence['hasActions'] = !$occurrence['cancelled'];
            $cancelUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'cancel', 'tracker_uuid' => $occurrence['eat_uuid'], 'event_uuid' => $occurrence['dat_uuid']));
            $restoreUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'restore', 'tracker_uuid' => $occurrence['eat_uuid'], 'event_uuid' => $occurrence['dat_uuid']));
            if ($mayManage) {
                $occurrence['hasActions'] = true;
                $occurrence['actions'] = $occurrence['cancelled'] ? array(
                    array('dataHref' => 'cancelEventAttendance(\'adm_event_attendance_occurrence_' . $occurrence['uuid'] . '\', \'' . $restoreUrl . '\', \'' . $gCurrentSession->getCsrfToken() . '\');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RESTORE_CONFIRM', array($occurrence['dat_headline'])), 'icon' => 'bi bi-arrow-counterclockwise', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RESTORE'))
                ) : array(
                    array('url' => $occurrence['url'], 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE')),
                    array('dataHref' => 'cancelEventAttendance(\'adm_event_attendance_occurrence_' . $occurrence['uuid'] . '\', \'' . $cancelUrl . '\', \'' . $gCurrentSession->getCsrfToken() . '\');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCEL_CONFIRM', array($occurrence['dat_headline'])), 'icon' => 'bi bi-x-circle-fill', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED'))
                );
            } else {
                $occurrence['actions'] = $occurrence['cancelled'] ? array() : array(
                    array('url' => $occurrence['url'], 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RECORD'))
                );
            }
        }
        unset($occurrence);
        $filterForm = new FormPresenter('adm_event_attendance_dashboard_filter_form', 'sys-template-parts/form.filter.tpl', '', $page, array('type' => 'navbar', 'setFocus' => false));
        $filterForm->addSelectBox('dashboard_event_period_filter', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EVENT_PERIOD'), array('future' => 'PLG_EVENT_ATTENDANCE_TRACKER_EVENT_PERIOD_FUTURE', 'past' => 'PLG_EVENT_ATTENDANCE_TRACKER_EVENT_PERIOD_PAST', 'all' => 'SYS_ALL'), array('defaultValue' => $eventPeriodFilter, 'showContextDependentFirstEntry' => false));
        $filterForm->addSelectBox('dashboard_tracker_filter', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CONFIGURATION'), $trackerFilterValues, array('defaultValue' => 'all', 'showContextDependentFirstEntry' => false));
        $filterForm->addToHtmlPage();
        $dashboardUrl = json_encode($plugin->getUrl('index.php'));
        $page->addJavascript('$("#dashboard_event_period_filter").on("change", function() { const url = new URL(' . $dashboardUrl . ', window.location.origin); url.searchParams.set("event_period_filter", $(this).val()); window.location.assign(url.toString()); });', true);
        $page->assignSmartyVariable('occurrences', $occurrences);
        if (count($occurrences) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_dashboard_table');
            $dataTables->setOrderColumns(array(array(2, 'asc')));
            $dataTables->disableColumnsSort(array(5));
            $page->addJavascript('$("#adm_event_attendance_dashboard_table").one("init.dt", function() {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    if (settings.nTable.id !== "adm_event_attendance_dashboard_table") {
                        return true;
                    }
                    const trackerUuid = $("#dashboard_tracker_filter").val();
                    return trackerUuid === "all" || settings.aoData[dataIndex].nTr.dataset.trackerUuid === trackerUuid;
                });
                $("#dashboard_tracker_filter").on("change", function() {
                    $("#adm_event_attendance_dashboard_table").DataTable().draw();
                });
                if ($("#dashboard_tracker_filter").val() !== "all") {
                    $("#adm_event_attendance_dashboard_table").DataTable().draw();
                }
            });
            ' . ($attendanceLockEnabled ? '$("#adm_event_attendance_dashboard_table").on("click", "a[href*=\"mode=attendance\"]", function(event) {
                event.preventDefault();
                const attendanceUrl = this.href;
                const lockUrl = new URL(attendanceUrl);
                lockUrl.searchParams.set("mode", "attendance_lock_acquire");
                $.post(lockUrl.toString(), { adm_csrf_token: ' . json_encode($gCurrentSession->getCsrfToken()) . ' }, function(response) {
                    const result = typeof response === "object" ? response : JSON.parse(response);
                    if (result.status === "success") {
                        window.location.assign(attendanceUrl);
                        return;
                    }
                    if (result.status === "locked") {
                        messageBox(result.message, ' . json_encode($gL10n->get('SYS_NOTE')) . ', "warning");
                    }
                });
            });' : '') . '
            window.cancelEventAttendance = function(rowId, url, csrfToken) {
                $.post(url, { adm_csrf_token: csrfToken }, function(response) {
                    const result = typeof response === "object" ? response : JSON.parse(response);
                    if (result.status === "success") {
                        $("#adm_modal_messagebox, #adm_modal").modal("hide");
                        window.location.reload();
                    }
                });
            };', true);
            $dataTables->createJavascript(count($occurrences), 5);
        }
        break;
    }
    $page->show();
} catch (Throwable $e) {
    handleException($e, isset($mode) && in_array($mode, array('save', 'save_edit', 'save_settings', 'update', 'check_update', 'activate', 'deactivate', 'delete', 'cancel', 'restore', 'save_attendance', 'attendance_lock_acquire', 'attendance_lock_heartbeat', 'clear_attendance_locks', 'save_self_excuses', 'toggle_self_excuse'), true));
}
