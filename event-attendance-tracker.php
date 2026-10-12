<?php

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\AttendanceFormPresenter;
use AdmidioPlugin\EventAttendance\Presenter\LegacySettingsPresenter;
use AdmidioPlugin\EventAttendance\Presenter\SelfExcusePresenter;
use AdmidioPlugin\EventAttendance\Presenter\TrackerFormPresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\PluginDefaults;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;
use AdmidioPlugin\EventAttendance\Repository\TrackerRepository;
use AdmidioPlugin\EventAttendance\Service\AttendanceService;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportExportService;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportService;
use AdmidioPlugin\EventAttendance\Service\AttendanceSnapshotService;
use AdmidioPlugin\EventAttendance\Service\CancellationService;
use AdmidioPlugin\EventAttendance\Service\TrackerAccess;
use AdmidioPlugin\EventAttendance\Service\TrackerService;
use AdmidioPlugin\EventAttendance\Service\LegacyPluginLifecycleService;
use AdmidioPlugin\EventAttendance\Service\LegacySettingsService;

try {
    require_once __DIR__ . '/../../system/common.php';
    require __DIR__ . '/../../system/login_valid.php';
    require_once __DIR__ . '/autoload.php';

    $plugin = LegacyPluginContext::initialize(__DIR__, $gDb, $gCurrentOrgId);
    $lifecycle = new LegacyPluginLifecycleService($gDb, __DIR__);
    $defaults = PluginDefaults::settings();
    if ($lifecycle->isInstalled() && $gCurrentUser->isAdministrator()) {
        $lifecycle->synchronizeMenuEntry($gCurrentOrgId, $plugin->settings());
    }
    $mode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'list'));
    $updateRequired = $lifecycle->isInstalled() && $lifecycle->needsUpdate($gCurrentOrgId, $defaults);
    if ($lifecycle->isInstalled() && $mode === 'manage' && !$updateRequired) {
        header('Location: ' . $plugin->getUrl('index.php') . '?mode=settings');
        exit;
    }
    if ($updateRequired && !in_array($mode, array('manage', 'update', 'uninstall'), true)) {
        $mode = 'manage';
    }
    if (!$lifecycle->isInstalled() || in_array($mode, array('manage', 'install', 'update', 'uninstall'), true)) {
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if (in_array($mode, array('install', 'uninstall', 'update'), true)) {
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
            if ($mode === 'install') { $lifecycle->install($gCurrentOrgId, $defaults); }
            elseif ($mode === 'uninstall') { $lifecycle->uninstall(); }
            else { $lifecycle->update($gCurrentOrgId, $defaults); }
            header('Location: ' . $plugin->getUrl('index.php') . '?mode=' . ($mode === 'uninstall' ? 'list' : 'settings') . '&result=' . $mode);
            exit;
        }
        $page = PagePresenter::withHtmlIDAndHeadline(
            'adm_event_attendance_manage',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NAME')
        );
        $page->addTemplateFolder($plugin->templatesDirectory());
        $result = admFuncVariableIsValid($_GET, 'result', 'string', array('defaultValue' => '', 'validValues' => array('', 'install', 'update', 'uninstall')));
        if (!$lifecycle->isInstalled()) {
            $page->assignSmartyVariable('availableVersion', (string) $defaults['event_attendance_schema_version']);
            $page->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
            $page->assignSmartyVariable('installUrl', $plugin->getUrl('index.php') . '?mode=install');
            $page->assignSmartyVariable(
                'resultMessage',
                $result === 'uninstall' ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL_SUCCESS') : ''
            );
            $page->addHtmlByTemplate('plugin.event-attendance.installation.tpl');
        } else {
            $page->assignSmartyVariable('installedVersion', (string) ($plugin->settings()['event_attendance_schema_version'] ?? ''));
            $page->assignSmartyVariable('availableVersion', (string) $defaults['event_attendance_schema_version']);
            $page->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
            $page->assignSmartyVariable('updateUrl', $plugin->getUrl('index.php') . '?mode=update');
            $page->addHtmlByTemplate('plugin.event-attendance.update-required.tpl');
        }
        $page->show();
        exit;
    }

    $mode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'list', 'validValues' => array('list', 'config', 'new', 'save', 'edit', 'save_edit', 'activate', 'deactivate', 'cancel', 'events', 'attendance', 'save_attendance', 'report', 'reports', 'report_pdf', 'report_print', 'report_export', 'my_events', 'save_self_excuses', 'settings', 'save_settings', 'check_update')));
    if (in_array($mode, array('settings', 'save_settings', 'check_update'), true)) {
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $settingsService = new LegacySettingsService($gDb);
        if ($mode === 'check_update') {
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
            header('Content-Type: application/json; charset=UTF-8');
            $updateAvailable = $lifecycle->needsUpdate($gCurrentOrgId, $defaults);
            echo json_encode(array('updateAvailable' => $updateAvailable, 'message' => $gL10n->get($updateAvailable ? 'PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_AVAILABLE' : 'PLG_EVENT_ATTENDANCE_TRACKER_UP_TO_DATE')));
            exit;
        }
        if ($mode === 'save_settings') {
            $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
            $formValues = $form->validate($_POST);
            $settings = $settingsService->get($gCurrentOrgId, $defaults);
            $panel = admFuncVariableIsValid($_GET, 'panel', 'string', array('validValues' => array('access', 'automation', 'rules', 'export')));
            if ($panel === 'access') {
                $settings['event_attendance_tracker_manager_roles'] = array_map('intval', $formValues['event_attendance_tracker_manager_roles'] ?? array());
            } elseif ($panel === 'automation') {
                $settings['event_attendance_automation_method'] = in_array($formValues['event_attendance_automation_method'] ?? '', array('disabled', 'cli', 'webcron'), true) ? $formValues['event_attendance_automation_method'] : 'disabled';
                $settings['event_attendance_webcron_token'] = trim((string) ($formValues['event_attendance_webcron_token'] ?? ''));
            } elseif ($panel === 'rules') {
                foreach (array('event_attendance_members_may_excuse_themselves', 'event_attendance_rate_counts_excused') as $name) {
                    // FormPresenter submits unchecked checkboxes explicitly as "0".
                    // isset() would therefore always evaluate to true here.
                    $settings[$name] = (string) ($formValues[$name] ?? '0') === '1';
                }
                foreach (array('event_attendance_self_excuse_deadline_hours', 'event_attendance_rate_decimal_places', 'event_attendance_rate_red_threshold', 'event_attendance_rate_orange_threshold', 'event_attendance_recording_lock_days') as $name) { $settings[$name] = max(0, (int) ($formValues[$name] ?? 0)); }
                $settings['event_attendance_rate_decimal_places'] = min(4, $settings['event_attendance_rate_decimal_places']);
                $settings['event_attendance_rate_red_threshold'] = min(100, $settings['event_attendance_rate_red_threshold']);
                $settings['event_attendance_rate_orange_threshold'] = min(100, max($settings['event_attendance_rate_red_threshold'], $settings['event_attendance_rate_orange_threshold']));
            } else {
                $settings['event_attendance_pdf_logo_file'] = trim((string) ($formValues['event_attendance_pdf_logo_file'] ?? ''));
                $settings['event_attendance_export_filename'] = trim((string) ($formValues['event_attendance_export_filename'] ?? ''));
            }
            $settingsService->save($gCurrentOrgId, $settings);
            $lifecycle->synchronizeMenuEntry($gCurrentOrgId, $settings);
            header('Location: ' . $plugin->getUrl('index.php') . '?mode=settings&saved=1');
            exit;
        }
        $settings = $settingsService->get($gCurrentOrgId, $defaults);
        $page = PagePresenter::withHtmlIDAndHeadline('adm_event_attendance_settings', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS'));
        $dashboardUrl = $plugin->getUrl('index.php');
        $gNavigation->addStartUrl($dashboardUrl, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE'), 'bi-person-check-fill');
        $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
        $page->addTemplateFolder($plugin->templatesDirectory());
        if (isset($_GET['saved'])) { $page->addHtml('<div class="alert alert-success">' . $gL10n->get('SYS_SAVE_DATA') . '</div>'); }
        LegacySettingsPresenter::create($page, $plugin, $settings);
        $page->assignSmartyVariable('installedVersion', (string) $settings['event_attendance_schema_version']);
        $page->assignSmartyVariable('updateAvailable', $lifecycle->needsUpdate($gCurrentOrgId, $defaults));
        $page->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
        $page->assignSmartyVariable('updateUrl', $plugin->getUrl('index.php') . '?mode=update');
        $page->assignSmartyVariable('uninstallUrl', $plugin->getUrl('index.php') . '?mode=uninstall');
        $page->assignSmartyVariable('checkUpdateUrl', $plugin->getUrl('index.php') . '?mode=check_update');
        $page->assignSmartyVariable('pluginDescription', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DESCRIPTION'));
        $page->addHtmlByTemplate('plugin.event-attendance.settings-management.tpl');
        $page->addHtml('</div>');
        $page->show();
        exit;
    }
    if ($mode === 'list' && !TrackerAccess::mayManage() && TrackerAccess::mayExcuseSelf()) {
        $mode = 'my_events';
    }
    if (!TrackerAccess::mayManage() && !in_array($mode, array('my_events', 'save_self_excuses'), true)) {
        throw new Exception('SYS_NO_RIGHTS');
    }
    $settings = $plugin->settings();
    $selfExcuseDeadlineHours = max(0, (int) ($settings['event_attendance_self_excuse_deadline_hours'] ?? 0));
    $recordingLockDays = max(0, (int) ($settings['event_attendance_recording_lock_days'] ?? 0));
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
        echo json_encode(array('status' => 'success', 'url' => SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'my_events'))));
        exit;
    }

    if ($mode === 'save' || $mode === 'save_edit') {
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        $service = new TrackerService($gDb);
        $calendarId = ($formValues['tracker_source'] ?? 'events') === 'calendar'
            ? (int) ($formValues['tracker_calendar'] ?? 0)
            : null;
        $arguments = array($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), (string) $formValues['tracker_name'], (string) $formValues['tracker_description'], $formValues['tracker_roles'] ?? array(), $formValues['tracker_events'] ?? array(), $calendarId);
        if ($mode === 'save') {
            $service->create(...$arguments);
        } else {
            array_splice($arguments, 1, 0, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'));
            $service->update(...$arguments);
        }
        echo json_encode(array('status' => 'success', 'url' => $plugin->getUrl('index.php')));
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

    $trackerUuid = isset($_GET['tracker_uuid']) ? admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid') : '';
    $eventUuid = isset($_GET['event_uuid']) ? admFuncVariableIsValid($_GET, 'event_uuid', 'uuid') : '';
    $trackers = new TrackerRepository($gDb);

    if ($mode === 'save_attendance') {
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $assertTrackerIsActive($tracker);
        $assertRecordingIsOpen($trackers, $tracker, $eventUuid);
        if (isset($_POST['adm_button_cancel_event'])) {
            (new CancellationService($gDb))->cancel((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'));
            echo json_encode(array('status' => 'success', 'url' => SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'events', 'tracker_uuid' => $trackerUuid))));
            exit;
        }
        // A manual recording needs its own expected-attendee snapshot, even when automation is disabled.
        (new AttendanceSnapshotService($gDb))->snapshotDueEventsForTracker((int) $tracker['eat_id'], new DateTimeImmutable(DATETIME_NOW));
        $roster = (new AttendanceRepository($gDb))->findRoster((int) $tracker['eat_id'], $eventUuid, $gProfileFields);
        $statuses = array();
        foreach ($roster as $person) {
            $statuses[(int) $person['eau_usr_id']] = (string) ($formValues['attendance_status_' . (int) $person['eau_usr_id']] ?? '');
        }
        (new AttendanceService($gDb))->save((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'), $statuses);
        echo json_encode(array('status' => 'success', 'url' => $plugin->getUrl('index.php')));
        exit;
    }

    $reportPercentageOperator = admFuncVariableIsValid($_GET, 'report_percentage_operator', 'string', array('defaultValue' => 'all', 'validValues' => array('all', 'lt', 'gt')));
    $reportPercentageValue = admFuncVariableIsValid($_GET, 'report_percentage_value', 'float', array('defaultValue' => 0.0));
    if ($reportPercentageValue < 0.0 || $reportPercentageValue > 100.0) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }
    if ($mode === 'report_print') {
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $printPage = PagePresenter::withHtmlIDAndHeadline('adm_event_attendance_report_print', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name']);
        $printPage->setPrintMode();
        $printPage->addTemplateFolder($plugin->templatesDirectory());
        $printPage->addTemplateFile('plugin.event-attendance.report.tpl');
        $printPage->assignSmartyVariable('rows', $reportService->getRows((int) $tracker['eat_id'], $reportPercentageOperator, $reportPercentageValue));
        $printPage->show();
        exit;
    }
    if ($mode === 'report_export') {
        $format = admFuncVariableIsValid($_GET, 'format', 'string', array('validValues' => array('csv', 'xlsx')));
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $reportExportService->createExport($tracker, $format, $reportPercentageOperator, $reportPercentageValue);
        exit;
    }

    if ($mode === 'report_pdf') {
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $reportExportService->createExport(
            $tracker,
            'pdf',
            $reportPercentageOperator,
            $reportPercentageValue,
            admFuncVariableIsValid($_GET, 'orientation', 'string', array('defaultValue' => 'L', 'validValues' => array('P', 'L')))
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
    $gNavigation->addStartUrl($dashboardUrl, $dashboardHeadline, 'bi-person-check-fill');
    if (in_array($mode, array('new', 'edit'), true)) {
        $gNavigation->addUrl(SecurityUtils::encodeUrl($dashboardUrl, array('mode' => 'config')), $gL10n->get('SYS_CONFIGURATION'));
    }
    if (!in_array($mode, array('list', 'attendance', 'report'), true)) {
        $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
    }
    $page->addTemplateFolder($plugin->templatesDirectory());
    if ($mode === 'list' && $gCurrentUser->isAdministrator()) {
        $settingsUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'settings'));
        // Keep the plugin administration as the first header action, before tracker actions.
        $page->addPageFunctionsMenuItem('adm_event_attendance_plugin_settings', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS'), $settingsUrl, 'bi-gear-fill');
        if ($lifecycle->needsUpdate($gCurrentOrgId, $defaults)) {
            $page->addHtml(
                '<div class="alert alert-warning">' . $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_AVAILABLE')
                . ' <a href="' . htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8') . '">'
                . $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS') . '</a></div>'
            );
        }
    }
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
        $page->setHeadline($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RECORD') . ': ' . $occurrence['dat_headline']);
        $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
        (new AttendanceFormPresenter())->create($page, $plugin->getUrl('index.php') . '?mode=save_attendance&tracker_uuid=' . $trackerUuid . '&event_uuid=' . $eventUuid, (new AttendanceRepository($gDb))->findRoster((int) $tracker['eat_id'], $eventUuid, $gProfileFields));
    } elseif ($mode === 'report') {
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $page->setHeadline($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name']);
        $gNavigation->addUrl(SecurityUtils::encodeUrl($dashboardUrl, array('mode' => 'reports')), $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORTS'));
        $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
        $filterForm = new FormPresenter('adm_event_attendance_report_filter_form', 'sys-template-parts/form.filter.tpl', SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'report', 'tracker_uuid' => $trackerUuid)), $page, array('type' => 'navbar', 'setFocus' => false));
        $filterForm->addSelectBox('report_percentage_operator', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_RATE'), array('all' => $gL10n->get('SYS_ALL'), 'lt' => '<', 'gt' => '>'), array('defaultValue' => $reportPercentageOperator, 'showContextDependentFirstEntry' => false));
        $filterForm->addInput('report_percentage_value', '%', (string) $reportPercentageValue, array('type' => 'text', 'class' => 'form-control-small'));
        $filterForm->addToHtmlPage();
        $page->addJavascript('$("#report_percentage_operator, #report_percentage_value").on("change", function() {
            const url = new URL(window.location.href);
            url.searchParams.set("mode", "report");
            url.searchParams.set("tracker_uuid", ' . json_encode($trackerUuid) . ');
            url.searchParams.set("report_percentage_operator", $("#report_percentage_operator").val());
            url.searchParams.set("report_percentage_value", $("#report_percentage_value").val());
            window.location.assign(url.toString());
        });', true);
        $page->addTemplateFile('plugin.event-attendance.report.tpl');
        $rows = $reportService->getRows((int) $tracker['eat_id'], $reportPercentageOperator, $reportPercentageValue);
        $page->assignSmartyVariable('rows', $rows);
        $exportParameters = array('tracker_uuid' => $trackerUuid, 'report_percentage_operator' => $reportPercentageOperator, 'report_percentage_value' => $reportPercentageValue);
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
        $page->addTemplateFile('plugin.event-attendance.events.tpl');
        $occurrences = $trackers->findOccurrences((int) $tracker['eat_id']);
        foreach ($occurrences as &$occurrence) {
            $occurrence['url'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'attendance', 'tracker_uuid' => $trackerUuid, 'event_uuid' => $occurrence['dat_uuid']));
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
            $tracker['editUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'edit', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['activateUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'activate', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['deactivateUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'deactivate', 'tracker_uuid' => $tracker['eat_uuid']));
            $tracker['activateMessage'] = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ACTIVATE_CONFIRM', array($tracker['eat_name']));
            $tracker['deactivateMessage'] = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DEACTIVATE_CONFIRM', array($tracker['eat_name']));
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
        }
        unset($tracker);
        $page->assignSmartyVariable('trackers', $allTrackers);
        if (count($allTrackers) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_trackers_table');
            $dataTables->disableColumnsSort(array(4));
            $dataTables->createJavascript(count($allTrackers), 4);
            $configurationUrl = json_encode(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config')));
            $page->addJavascript('
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
            ', true);
        }
    } else {
        $page->addTemplateFile('plugin.event-attendance.dashboard.tpl');
        $occurrences = $trackers->findActiveOccurrences($gCurrentOrgId);
        foreach ($occurrences as &$occurrence) {
            $occurrence['url'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'attendance', 'tracker_uuid' => $occurrence['eat_uuid'], 'event_uuid' => $occurrence['dat_uuid']));
            $occurrence['uuid'] = $occurrence['eat_uuid'] . '_' . $occurrence['dat_uuid'];
            $occurrence['status'] = $occurrence['cancelled'] ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED') : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNKNOWN');
            $occurrence['hasActions'] = !$occurrence['cancelled'];
            $cancelUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'cancel', 'tracker_uuid' => $occurrence['eat_uuid'], 'event_uuid' => $occurrence['dat_uuid']));
            $occurrence['actions'] = $occurrence['cancelled'] ? array() : array(
                array('url' => $occurrence['url'], 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE')),
                array('dataHref' => 'cancelEventAttendance(' . json_encode('adm_event_attendance_occurrence_' . $occurrence['uuid']) . ', ' . json_encode($cancelUrl) . ', ' . json_encode($gCurrentSession->getCsrfToken()) . ');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCEL_CONFIRM', array($occurrence['dat_headline'])), 'icon' => 'bi bi-x-circle-fill', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED'))
            );
        }
        unset($occurrence);
        $page->assignSmartyVariable('occurrences', $occurrences);
        if (count($occurrences) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_dashboard_table');
            $dataTables->setOrderColumns(array(array(3, 'desc')));
            $dataTables->disableColumnsSort(array(5));
            $dataTables->createJavascript(count($occurrences), 5);
            $page->addJavascript('window.cancelEventAttendance = function(rowId, url, csrfToken) {
                $.post(url, { adm_csrf_token: csrfToken }, function(response) {
                    const result = typeof response === "object" ? response : JSON.parse(response);
                    if (result.status === "success") {
                        $("#adm_modal_messagebox, #adm_modal").modal("hide");
                        window.location.reload();
                    }
                });
            };', true);
        }
    }
    $page->show();
} catch (Throwable $e) {
    handleException($e, isset($mode) && in_array($mode, array('save', 'save_edit', 'activate', 'deactivate', 'cancel', 'save_attendance', 'save_self_excuses'), true));
}
