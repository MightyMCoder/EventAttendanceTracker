<?php
/**
 * Handles HTTP requests for the Event Attendance Tracker plugin.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use Admidio\Users\Entity\User;
use AdmidioPlugin\EventAttendance\Presenter\AttendanceFormPresenter;
use AdmidioPlugin\EventAttendance\Presenter\SelfExcusePresenter;
use AdmidioPlugin\EventAttendance\Presenter\TrackerFormPresenter;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;
use AdmidioPlugin\EventAttendance\Repository\TrackerRepository;
use AdmidioPlugin\EventAttendance\Service\AttendanceService;
use AdmidioPlugin\EventAttendance\Service\AttendanceLockService;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportExportService;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportService;
use AdmidioPlugin\EventAttendance\Service\AttendanceSnapshotService;
use AdmidioPlugin\EventAttendance\Service\CancellationService;
use AdmidioPlugin\EventAttendance\Service\TrackerAccess;
use AdmidioPlugin\EventAttendance\Service\TrackerService;

try {
    require_once __DIR__ . '/../../../system/common.php';
    require __DIR__ . '/../../../system/login_valid.php';

    $mode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'list', 'validValues' => array('list', 'config', 'new', 'save', 'edit', 'save_edit', 'activate', 'deactivate', 'delete', 'cancel', 'restore', 'events', 'attendance', 'attendance_lock_acquire', 'attendance_lock_heartbeat', 'clear_attendance_locks', 'save_attendance', 'report', 'reports', 'report_pdf', 'report_print', 'report_export', 'my_events', 'save_self_excuses', 'toggle_self_excuse')));
    if ($mode === 'list' && !TrackerAccess::mayRecordAttendance() && TrackerAccess::mayExcuseSelf()) {
        $mode = 'my_events';
    }
    if (in_array($mode, array('config', 'new', 'save', 'edit', 'save_edit', 'activate', 'deactivate', 'delete', 'cancel', 'restore', 'report', 'reports', 'report_pdf', 'report_print', 'report_export'), true)
        && !TrackerAccess::mayManage()) {
        throw new Exception('SYS_NO_RIGHTS');
    }
    if (in_array($mode, array('list', 'events', 'attendance', 'attendance_lock_acquire', 'attendance_lock_heartbeat', 'save_attendance'), true)
        && !TrackerAccess::mayRecordAttendance()) {
        throw new Exception('SYS_NO_RIGHTS');
    }
    if (in_array($mode, array('my_events', 'save_self_excuses', 'toggle_self_excuse'), true)
        && !TrackerAccess::mayExcuseSelf()) {
        throw new Exception('SYS_NO_RIGHTS');
    }
    $plugin = PluginRegistry::requireEnabled('event-attendance');
    $settings = $plugin->getSettingValues();
    $selfExcuseDeadlineHours = max(0, (int) ($settings['event_attendance_self_excuse_deadline_hours'] ?? 0));
    $recordingLockDays = max(0, (int) ($settings['event_attendance_recording_lock_days'] ?? 0));
    $attendanceLockEnabled = (bool) ($settings['event_attendance_attendance_lock_enabled'] ?? false);
    $attendanceLockDurationMinutes = min(1440, max(1, (int) ($settings['event_attendance_attendance_lock_duration_minutes'] ?? 5)));
    $attendanceLockService = new AttendanceLockService($gDb, $attendanceLockDurationMinutes * 60);
    $eventPeriodFilter = admFuncVariableIsValid($_GET, 'event_period_filter', 'string', array('defaultValue' => 'future', 'validValues' => array('future', 'past', 'all')));
    $reportService = new AttendanceReportService($gDb, $gProfileFields, $settings, $gL10n);
    $reportExportService = new AttendanceReportExportService($gDb, $plugin, $reportService, $settings);
    $assertRecordingIsOpen = static function (TrackerRepository $trackers, array $tracker, string $eventUuid) use ($recordingLockDays): void {
        if ($recordingLockDays === 0) {
            return;
        }

        $occurrences = $trackers->findOccurrences((int) $tracker['eat_id']);
        $occurrence = array_values(array_filter($occurrences, static fn (array $entry): bool => $entry['dat_uuid'] === $eventUuid))[0] ?? false;
        if ($occurrence === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $eventEnd = new DateTimeImmutable($occurrence['dat_end'] ?: $occurrence['dat_begin']);
        if ($eventEnd->modify('+' . $recordingLockDays . ' days') <= new DateTimeImmutable(DATETIME_NOW)) {
            throw new Exception('PLG_EVENT_ATTENDANCE_TRACKER_RECORDING_LOCKED');
        }
    };
    $assertTrackerIsActive = static function (array $tracker): void {
        if (!(bool) $tracker['eat_active']) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
    };

    if ($mode === 'clear_attendance_locks') {
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $attendanceLockService->clearAll($gCurrentOrgId);
        echo json_encode(array('status' => 'success'));
        exit;
    }

    if ($mode === 'save_self_excuses') {
        if (!TrackerAccess::mayExcuseSelf()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        $selected = $formValues['self_excuse_events'] ?? array();
        $events = (new AttendanceRepository($gDb))->findSelfExcusableEvents($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours);
        foreach ($events as $event) {
            if (in_array($event['eat_uuid'] . ':' . $event['dat_uuid'], $selected, true)) {
                (new AttendanceService($gDb))->excuseSelf((int) $event['eat_id'], $event['dat_uuid'], (int) $gCurrentUser->getValue('usr_id'));
            } elseif ($event['ear_status'] === 'excused') {
                (new AttendanceService($gDb))->withdrawSelfExcuse((int) $event['eat_id'], $event['dat_uuid'], (int) $gCurrentUser->getValue('usr_id'));
            }
        }
        echo json_encode(array('status' => 'success', 'message' => $gL10n->get('SYS_SAVE_DATA'), 'url' => SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'my_events'))));
        exit;
    }
    if ($mode === 'toggle_self_excuse') {
        if (!TrackerAccess::mayExcuseSelf()) { throw new Exception('SYS_NO_RIGHTS'); }
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $eventUuid = admFuncVariableIsValid($_POST, 'event_uuid', 'uuid');
        $excuse = admFuncVariableIsValid($_POST, 'action', 'string', array('validValues' => array('excuse', 'withdraw'))) === 'excuse';
        $scope = admFuncVariableIsValid($_POST, 'scope', 'string', array('defaultValue' => 'single', 'validValues' => array('single', 'all')));
        $service = new AttendanceService($gDb);
        if ($scope === 'all') {
            $service->setSelfExcuseForEvent($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours, $eventUuid, $excuse);
            echo json_encode(array('status' => 'success'));
            exit;
        }
        $trackerUuid = admFuncVariableIsValid($_POST, 'tracker_uuid', 'uuid');
        echo json_encode(array('status' => 'success', 'additionalConfigurations' => $service->setSelfExcuseForConfiguration($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours, $trackerUuid, $eventUuid, $excuse)));
        exit;
    }

    if ($mode === 'save' || $mode === 'save_edit') {
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $source = admFuncVariableIsValid($_POST, 'tracker_source', 'string', array('validValues' => array('events', 'calendar')));
        if (!$form instanceof FormPresenter) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $disabledFields = array($source === 'calendar' ? 'tracker_events' : 'tracker_calendar');
        // Modal controls are drafts; the service validates the complete list.
        $disabledFields = array_merge($disabledFields, array('excuse_id', 'excuse_user', 'excuse_from', 'excuse_until'));
        foreach ($disabledFields as $field) {
            $element = $form->getElement($field);
            if ($element === null) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
            $element['property'] = FormPresenter::FIELD_DISABLED;
            $form->replaceElement($field, $element);
        }
        $formValues = $form->validate($_POST);
        $eventIds = $source === 'events' ? ($formValues['tracker_events'] ?? array()) : array();
        $calendarId = $source === 'calendar' ? (int) ($formValues['tracker_calendar'] ?? 0) : null;
        $service = new TrackerService($gDb);
        $arguments = array($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), (string) $formValues['tracker_name'], (string) $formValues['tracker_description'], $formValues['tracker_roles'] ?? array(), $eventIds, $calendarId);
        $rows = json_decode((string) ($formValues['tracker_permanent_excuses'] ?? ($mode === 'save' ? '[]' : '')), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows)) {
            throw new \InvalidArgumentException('Invalid permanent-excuse list.');
        }
        $arguments[] = $rows;
        if ($mode === 'save') {
            $service->create(...$arguments);
        } else {
            array_splice($arguments, 1, 0, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'));
            $service->update(...$arguments);
        }
        echo json_encode(array('status' => 'success', 'message' => $gL10n->get('SYS_SAVE_DATA'), 'url' => SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config'))));
        exit;
    }

    if ($mode === 'deactivate') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        (new TrackerService($gDb))->deactivate($gCurrentOrgId, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'), (int) $gCurrentUser->getValue('usr_id'));
        echo json_encode(array('status' => 'success'));
        exit;
    }
    if ($mode === 'activate') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        (new TrackerService($gDb))->activate($gCurrentOrgId, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'), (int) $gCurrentUser->getValue('usr_id'));
        echo json_encode(array('status' => 'success'));
        exit;
    }
    if ($mode === 'delete') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        (new TrackerService($gDb))->delete($gCurrentOrgId, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'));
        echo json_encode(array('status' => 'success'));
        exit;
    }
    if ($mode === 'cancel') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $trackers = new TrackerRepository($gDb);
        $tracker = $trackers->findByUuid($gCurrentOrgId, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'));
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $assertTrackerIsActive($tracker);
        $eventUuid = admFuncVariableIsValid($_GET, 'event_uuid', 'uuid');
        $assertRecordingIsOpen($trackers, $tracker, $eventUuid);
        (new CancellationService($gDb))->cancel((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'));
        echo json_encode(array('status' => 'success'));
        exit;
    }
    if ($mode === 'restore') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $tracker = (new TrackerRepository($gDb))->findByUuid($gCurrentOrgId, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'));
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        (new AttendanceService($gDb))->restore((int) $tracker['eat_id'], admFuncVariableIsValid($_GET, 'event_uuid', 'uuid'));
        echo json_encode(array('status' => 'success'));
        exit;
    }

    $trackerUuid = isset($_GET['tracker_uuid']) ? admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid') : '';
    $eventUuid = isset($_GET['event_uuid']) ? admFuncVariableIsValid($_GET, 'event_uuid', 'uuid') : '';
    $attendanceReturnTo = admFuncVariableIsValid($_GET, 'return_to', 'string', array('defaultValue' => 'list', 'validValues' => array('list', 'events')));
    $attendanceReturnPeriod = admFuncVariableIsValid($_GET, 'return_period', 'string', array('defaultValue' => 'future', 'validValues' => array('future', 'past', 'all')));
    $attendanceReturnParameters = array('mode' => $attendanceReturnTo, 'event_period_filter' => $attendanceReturnPeriod);
    if ($attendanceReturnTo === 'events' && $trackerUuid !== '') {
        $attendanceReturnParameters['tracker_uuid'] = $trackerUuid;
    }
    $attendanceReturnUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), $attendanceReturnParameters);
    $trackers = new TrackerRepository($gDb);

    if ($mode === 'attendance_lock_heartbeat') {
        if (!$attendanceLockEnabled) {
            echo json_encode(array('status' => 'locked'));
            exit;
        }
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $assertTrackerIsActive($tracker);
        $assertRecordingIsOpen($trackers, $tracker, $eventUuid);
        $lockToken = (string) ($_POST['attendance_lock_token'] ?? '');
        echo json_encode(array(
            'status' => $attendanceLockService->refresh((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $lockToken) ? 'success' : 'locked'
        ));
        exit;
    }

    if ($mode === 'attendance_lock_acquire') {
        if (!$attendanceLockEnabled) {
            echo json_encode(array('status' => 'success'));
            exit;
        }
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $assertTrackerIsActive($tracker);
        $assertRecordingIsOpen($trackers, $tracker, $eventUuid);
        $lock = $attendanceLockService->acquire(
            (int) $tracker['eat_id'],
            $eventUuid,
            (int) $gCurrentUser->getValue('usr_id'),
            (string) $gCurrentSession->getValue('ses_session_id')
        );
        if ($lock['token'] === null) {
            $lockOwner = new User($gDb, $gProfileFields, (int) $lock['lockedUserId']);
            $lockOwnerName = trim($lockOwner->getValue('FIRST_NAME', 'html') . ' ' . $lockOwner->getValue('LAST_NAME', 'html'));
            echo json_encode(array(
                'status' => 'locked',
                'message' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCKED', array($lockOwnerName))
            ));
            exit;
        }
        echo json_encode(array('status' => 'success'));
        exit;
    }

    if ($mode === 'save_attendance') {
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        $lockToken = (string) ($formValues['attendance_lock_token'] ?? '');
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $assertTrackerIsActive($tracker);
        $assertRecordingIsOpen($trackers, $tracker, $eventUuid);
        if ($attendanceLockEnabled && !$attendanceLockService->refresh((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $lockToken)) {
            throw new Exception('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCK_LOST');
        }
        if (isset($_POST['adm_button_cancel_event'])) {
            if (!TrackerAccess::mayManage()) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            (new CancellationService($gDb))->cancel((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'));
            if ($attendanceLockEnabled) {
                $attendanceLockService->release((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $lockToken);
            }
            echo json_encode(array('status' => 'success', 'message' => $gL10n->get('SYS_SAVE_DATA'), 'url' => $attendanceReturnUrl));
            exit;
        }
        (new AttendanceService($gDb))->saveForm((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $formValues, $gProfileFields);
        if ($attendanceLockEnabled) {
            $attendanceLockService->release((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $lockToken);
        }
        echo json_encode(array('status' => 'success', 'message' => $gL10n->get('SYS_SAVE_DATA'), 'url' => $attendanceReturnUrl));
        exit;
    }

    $reportPercentageOperator = admFuncVariableIsValid($_GET, 'report_percentage_operator', 'string', array('defaultValue' => 'all', 'validValues' => array('all', 'lt', 'gt')));
    $reportPercentageValue = admFuncVariableIsValid($_GET, 'report_percentage_value', 'float', array('defaultValue' => 0.0));
    if ($reportPercentageValue < 0.0 || $reportPercentageValue > 100.0) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }
    $parseReportDate = static function (string $parameter): ?string {
        global $gSettingsManager;
        if (!isset($_GET[$parameter]) || $_GET[$parameter] === '') { return null; }
        $value = admFuncVariableIsValid($_GET, $parameter, 'date');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: DateTimeImmutable::createFromFormat('!' . $gSettingsManager->getString('system_date'), $value);
        if ($date === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        return $date->format('Y-m-d');
    };
    $resolveReport = static function () use ($trackers, $gCurrentOrgId, $trackerUuid, $parseReportDate): array {
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $dateFrom = $parseReportDate('report_date_from');
        $dateUntil = $parseReportDate('report_date_until');
        $defaults = $trackers->getReportPeriodDefaults((int) $tracker['eat_id']);
        $dateFrom = array_key_exists('report_date_from', $_GET) ? $dateFrom : $defaults['dateFrom'];
        $dateUntil = array_key_exists('report_date_until', $_GET) ? $dateUntil : $defaults['dateUntil'];
        if ($dateFrom !== null && $dateUntil !== null && $dateFrom > $dateUntil) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        return array($tracker, $dateFrom, $dateUntil);
    };
    if ($mode === 'report_print') {
        [$tracker, $reportDateFrom, $reportDateUntil] = $resolveReport();
        $printPage = PagePresenter::withHtmlIDAndHeadline('adm_event_attendance_report_print', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name']);
        $printPage->setPrintMode();
        $printPage->addTemplateFolder($plugin->getDirectory(Plugin::DIR_TEMPLATES));
        $printPage->addTemplateFile('plugin.event-attendance.report.tpl');
        $printPage->assignSmartyVariable('rows', $reportService->getRows((int) $tracker['eat_id'], $reportPercentageOperator, $reportPercentageValue, $reportDateFrom, $reportDateUntil));
        $printPage->show();
        exit;
    }
    if ($mode === 'report_export') {
        $format = admFuncVariableIsValid($_GET, 'format', 'string', array('validValues' => array('csv', 'xlsx')));
        [$tracker, $reportDateFrom, $reportDateUntil] = $resolveReport();
        $reportExportService->createExport($tracker, $format, $reportPercentageOperator, $reportPercentageValue, 'L', $reportDateFrom, $reportDateUntil);
        exit;
    }

    if ($mode === 'report_pdf') {
        [$tracker, $reportDateFrom, $reportDateUntil] = $resolveReport();
        $reportExportService->createExport(
            $tracker,
            'pdf',
            $reportPercentageOperator,
            $reportPercentageValue,
            admFuncVariableIsValid($_GET, 'orientation', 'string', array('defaultValue' => 'L', 'validValues' => array('P', 'L'))),
            $reportDateFrom,
            $reportDateUntil
        );
        exit;
    }

    $headline = match ($mode) {
        'new' => $gL10n->get('SYS_CREATE'),
        'my_events' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF'),
        'config' => $gL10n->get('SYS_CONFIGURATION'),
        'reports' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORTS'),
        default => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE')
    };
    $page = PagePresenter::withHtmlIDAndHeadline('adm_event_attendance', $headline);
    $dashboardUrl = $plugin->getUrl('index.php');
    $dashboardHeadline = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE');
    $gNavigation->addStartUrl($dashboardUrl, $dashboardHeadline, $plugin->icon);
    if (in_array($mode, array('new', 'edit'), true)) {
        $gNavigation->addUrl(SecurityUtils::encodeUrl($dashboardUrl, array('mode' => 'config')), $gL10n->get('SYS_CONFIGURATION'));
    }
    if (!in_array($mode, array('list', 'attendance', 'report'), true)) {
        $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
    }
    $page->addTemplateFolder($plugin->getDirectory(Plugin::DIR_TEMPLATES));
    if ($mode === 'list' && TrackerAccess::mayManage()) {
        $page->addPageFunctionsMenuItem('adm_event_attendance_configuration', $gL10n->get('SYS_CONFIGURATION'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config')), 'bi-gear-fill');
        $page->addPageFunctionsMenuItem('adm_event_attendance_reports', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORTS'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'reports')), 'bi-bar-chart-fill');
    }
    if ($mode === 'list' && TrackerAccess::mayExcuseSelf()) {
        $page->addPageFunctionsMenuItem('adm_event_attendance_self_excuse', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'my_events')), 'bi-person-x-fill');
    }
    if ($mode === 'new') {
        (new TrackerFormPresenter())->create($page, $plugin);
    } elseif ($mode === 'edit') {
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $assertTrackerIsActive($tracker);
        (new TrackerFormPresenter())->create($page, $plugin, $tracker, $trackers->findConfiguration((int) $tracker['eat_id']));
    } elseif ($mode === 'my_events') {
        if (!TrackerAccess::mayExcuseSelf()) { throw new Exception('SYS_NO_RIGHTS'); }
        (new SelfExcusePresenter())->create($page, $plugin, (new AttendanceRepository($gDb))->findSelfExcusableEvents($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours));
    } elseif ($mode === 'attendance') {
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $assertTrackerIsActive($tracker);
        $occurrences = $trackers->findOccurrences((int) $tracker['eat_id']);
        $occurrence = array_values(array_filter($occurrences, static fn (array $entry): bool => $entry['dat_uuid'] === $eventUuid))[0] ?? false;
        if ($occurrence === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $assertRecordingIsOpen($trackers, $tracker, $eventUuid);
        $page->setHeadline($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RECORD') . ': ' . $occurrence['dat_headline']);
        if ($attendanceReturnTo === 'events') {
            $gNavigation->addUrl($attendanceReturnUrl, $tracker['eat_name']);
        }
        $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
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
        } else {
            (new AttendanceFormPresenter())->create(
                $page,
                SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'save_attendance', 'tracker_uuid' => $trackerUuid, 'event_uuid' => $eventUuid, 'return_to' => $attendanceReturnTo, 'return_period' => $attendanceReturnPeriod)),
                (new AttendanceRepository($gDb))->findRoster((int) $tracker['eat_id'], $eventUuid, $gProfileFields),
                $lockToken,
                $plugin->getUrl('index.php') . '?mode=attendance_lock_heartbeat&tracker_uuid=' . $trackerUuid . '&event_uuid=' . $eventUuid,
                $attendanceLockService->getHeartbeatIntervalSeconds(),
                $attendanceReturnUrl
            );
        }
    } elseif ($mode === 'report') {
        [$tracker, $reportDateFrom, $reportDateUntil] = $resolveReport();
        $page->setHeadline($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name']);
        $gNavigation->addUrl(SecurityUtils::encodeUrl($dashboardUrl, array('mode' => 'reports')), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORTS'));
        $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
        $filterForm = new FormPresenter('adm_event_attendance_report_filter_form', 'sys-template-parts/form.filter.tpl', SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'report', 'tracker_uuid' => $trackerUuid)), $page, array('type' => 'navbar', 'setFocus' => false));
        $filterForm->addInput('report_date_from', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT_DATE_FROM'), $reportDateFrom ?? '', array('type' => 'date', 'maxLength' => 10));
        $filterForm->addInput('report_date_until', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT_DATE_UNTIL'), $reportDateUntil ?? '', array('type' => 'date', 'maxLength' => 10));
        $filterForm->addSelectBox('report_percentage_operator', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_RATE'), array('all' => $gL10n->get('SYS_ALL'), 'lt' => '<', 'gt' => '>'), array('defaultValue' => $reportPercentageOperator, 'showContextDependentFirstEntry' => false));
        $filterForm->addInput('report_percentage_value', '%', (string) $reportPercentageValue, array('type' => 'text', 'class' => 'form-control-small'));
        $filterForm->addButton('adm_event_attendance_report_filter_reset', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RESET_FILTER'), array('icon' => 'bi-arrow-counterclockwise'));
        $filterForm->addToHtmlPage();
        $page->addJavascript('$("#report_date_from, #report_date_until, #report_percentage_operator, #report_percentage_value").on("change", function() {
            const url = new URL(window.location.href);
            url.searchParams.set("mode", "report");
            url.searchParams.set("tracker_uuid", ' . json_encode($trackerUuid) . ');
            url.searchParams.set("report_date_from", $("#report_date_from").val());
            url.searchParams.set("report_date_until", $("#report_date_until").val());
            url.searchParams.set("report_percentage_operator", $("#report_percentage_operator").val());
            url.searchParams.set("report_percentage_value", $("#report_percentage_value").val());
            window.location.assign(url.toString());
        });', true);
        $page->addJavascript('$("#adm_event_attendance_report_filter_reset").on("click", function() {
            window.location.assign(' . json_encode(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'report', 'tracker_uuid' => $trackerUuid))) . ');
        });', true);
        $page->addTemplateFile('plugin.event-attendance.report.tpl');
        $rows = $reportService->getRows((int) $tracker['eat_id'], $reportPercentageOperator, $reportPercentageValue, $reportDateFrom, $reportDateUntil);
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
    } elseif ($mode === 'reports') {
        $page->addTemplateFile('plugin.event-attendance.reports.tpl');
        $allTrackers = $trackers->findAll($gCurrentOrgId);
        foreach ($allTrackers as &$tracker) {
            $tracker['reportUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'report', 'tracker_uuid' => $tracker['eat_uuid']));
        }
        unset($tracker);
        $page->assignSmartyVariable('trackers', $allTrackers);
    } elseif ($mode === 'events') {
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $page->setHeadline($tracker['eat_name']);
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
            (new DataTables($page, 'adm_event_attendance_events_table'))->createJavascript(count($occurrences), 3);
        }
    } elseif ($mode === 'config') {
        $page->addPageFunctionsMenuItem('adm_event_attendance_create_tracker', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ADD_TRACKER'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'new')), 'bi-plus-circle-fill');
        $filterForm = new FormPresenter('adm_event_attendance_tracker_filter_form', 'sys-template-parts/form.filter.tpl', '', $page, array('type' => 'navbar', 'setFocus' => false));
        $filterForm->addSelectBox('tracker_active_filter', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_STATUS'), array('all' => $gL10n->get('SYS_ALL'), 'active' => $gL10n->get('SYS_ENABLED'), 'inactive' => $gL10n->get('SYS_DISABLED')), array('defaultValue' => 'all', 'showContextDependentFirstEntry' => false));
        $filterForm->addToHtmlPage();
        $page->addTemplateFile('plugin.event-attendance.trackers.tpl');
        $allTrackers = $trackers->findAll($gCurrentOrgId);
        foreach ($allTrackers as &$tracker) {
            $tracker['source'] = (int) ($tracker['eat_cat_id'] ?? 0) > 0
                ? (string) $tracker['calendar_name']
                : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_EVENTS');
            $tracker['editUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'edit', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['activateUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'activate', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['deactivateUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'deactivate', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['deleteUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'delete', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['activateMessage'] = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ACTIVATE_CONFIRM', array($tracker['eat_name']));
            $tracker['deactivateMessage'] = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DEACTIVATE_CONFIRM', array($tracker['eat_name']));
            $tracker['uuid'] = $tracker['eat_uuid'];
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
            $tracker['actions'][] = array('dataHref' => 'deleteEventAttendanceTracker(' . json_encode($tracker['deleteUrl']) . ', ' . json_encode($gCurrentSession->getCsrfToken()) . ');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DELETE_CONFIRM', array($tracker['eat_name'])), 'icon' => 'bi bi-trash-fill text-danger', 'tooltip' => $gL10n->get('SYS_DELETE'));
        }
        unset($tracker);
        $page->assignSmartyVariable('trackers', $allTrackers);
        if (count($allTrackers) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_trackers_table');
            $dataTables->disableColumnsSort(array(4));
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
                    $(this).DataTable().draw();
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
                window.deleteEventAttendanceTracker = function(url, csrfToken) {
                    $.post(url, { adm_csrf_token: csrfToken }, function(response) {
                        const result = typeof response === "object" ? response : JSON.parse(response);
                        if (result.status === "success") {
                            $("#adm_modal_messagebox, #adm_modal").modal("hide");
                            window.location.href = ' . $configurationUrl . ';
                        }
                    });
                };
            ', true);
            $dataTables->createJavascript(count($allTrackers), 4);
        }
    } else {
        $page->addTemplateFile('plugin.event-attendance.dashboard.tpl');
        $occurrences = $trackers->findActiveOccurrences($gCurrentOrgId, $eventPeriodFilter);
        $trackerFilterValues = array('all' => $gL10n->get('SYS_ALL'));
        $now = new DateTimeImmutable(DATETIME_NOW);
        $mayManage = TrackerAccess::mayManage();
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
                    array('dataHref' => 'restoreEventAttendance(' . json_encode($restoreUrl) . ', ' . json_encode($gCurrentSession->getCsrfToken()) . ');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RESTORE_CONFIRM', array($occurrence['dat_headline'])), 'icon' => 'bi bi-arrow-counterclockwise text-success', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RESTORE'))
                ) : array(
                    array('url' => $occurrence['url'], 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE')),
                    array('dataHref' => 'cancelEventAttendance(' . json_encode('adm_event_attendance_occurrence_' . $occurrence['uuid']) . ', ' . json_encode($cancelUrl) . ', ' . json_encode($gCurrentSession->getCsrfToken()) . ');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCEL_CONFIRM', array($occurrence['dat_headline'])), 'icon' => 'bi bi-x-circle-fill', 'tooltip' => $gL10n->get('SYS_CANCELLED'))
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
        $dashboardFilterUrl = json_encode($plugin->getUrl('index.php'));
        $page->addJavascript('$("#dashboard_event_period_filter").on("change", function() { const url = new URL(' . $dashboardFilterUrl . ', window.location.origin); url.searchParams.set("event_period_filter", $(this).val()); window.location.assign(url.toString()); });', true);
        $page->assignSmartyVariable('occurrences', $occurrences);
        if (count($occurrences) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_dashboard_table');
            $dataTables->setOrderColumns(array(array(3, 'desc')));
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
                $(this).DataTable().draw();
            }
            });
            ' . ($attendanceLockEnabled ? '$("#adm_event_attendance_dashboard_table").on("click", "a[href*=\\"mode=attendance\\"]", function(event) {
                event.preventDefault();
                const attendanceUrl = this.href;
                const lockUrl = new URL(attendanceUrl);
                lockUrl.searchParams.set("mode", "attendance_lock_acquire");
                $.post(lockUrl.toString(), { adm_csrf_token: ' . json_encode($gCurrentSession->getCsrfToken()) . ' }, function(response) {
                    const result = typeof response === "object" ? response : JSON.parse(response);
                    if (result.status === "success") {
                        window.location.assign(attendanceUrl);
                    } else if (result.status === "locked") {
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
            };
            window.restoreEventAttendance = function(url, csrfToken) {
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
    }
    $page->show();
} catch (Throwable $e) {
    handleException($e, isset($mode) && in_array($mode, array('save', 'save_edit', 'activate', 'deactivate', 'delete', 'cancel', 'restore', 'attendance_lock_acquire', 'attendance_lock_heartbeat', 'clear_attendance_locks', 'save_attendance', 'save_self_excuses'), true));
}
