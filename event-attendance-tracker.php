<?php

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\EventAttendance;
use AdmidioPlugin\EventAttendance\Presenter\AttendanceFormPresenter;
use AdmidioPlugin\EventAttendance\Presenter\AttendancePagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\DashboardPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\EventsPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\JsonResponsePresenter;
use AdmidioPlugin\EventAttendance\Presenter\LegacySettingsPresenter;
use AdmidioPlugin\EventAttendance\Presenter\ManagementPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\PrintReportPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\ReportPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\ReportsPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\SelfExcusePresenter;
use AdmidioPlugin\EventAttendance\Presenter\SelfExcusePagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\SettingsPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\SettingsUpdatePresenter;
use AdmidioPlugin\EventAttendance\Presenter\TrackerConfigurationPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\TrackerFormPresenter;
use AdmidioPlugin\EventAttendance\Presenter\TrackerEditPagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\UpdateFormPresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\PluginDefaults;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;
use AdmidioPlugin\EventAttendance\Repository\TrackerRepository;
use AdmidioPlugin\EventAttendance\Service\AttendanceRecordingAccessService;
use AdmidioPlugin\EventAttendance\Service\AttendanceActionService;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportExportService;
use AdmidioPlugin\EventAttendance\Service\AttendanceReportService;
use AdmidioPlugin\EventAttendance\Service\TrackerAccess;
use AdmidioPlugin\EventAttendance\Service\LegacyPluginLifecycleService;
use AdmidioPlugin\EventAttendance\Service\LegacySettingsService;
use AdmidioPlugin\EventAttendance\Service\ReleaseCheckService;
use AdmidioPlugin\EventAttendance\Service\SelfExcuseActionService;
use AdmidioPlugin\EventAttendance\Service\TrackerActionService;

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
            if ($mode === 'update') {
                $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
                $form->validate($_POST);
            } else {
                SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
            }
            switch ($mode) {
                case 'install':
                    $lifecycle->install($gCurrentOrgId, $defaults);
                    break;

                case 'uninstall':
                    $lifecycle->uninstall();
                    break;

                case 'update':
                    $lifecycle->update($gCurrentOrgId, $defaults);
                    break;
            }

            if ($mode === 'update') {
                JsonResponsePresenter::send(array(
                    'status' => 'success',
                    'message' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_SUCCESS'),
                    'url' => $plugin->getUrl('index.php')
                ), JSON_THROW_ON_ERROR);
                exit;
            }
            $redirectMode = $mode === 'uninstall' ? 'list' : 'settings';
            admRedirect(SecurityUtils::encodeUrl(
                $plugin->getUrl('index.php'),
                array('mode' => $redirectMode, 'result' => $mode)
            ));
        }
        $page = ManagementPagePresenter::create($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NAME'));
        $result = admFuncVariableIsValid($_GET, 'result', 'string', array('defaultValue' => '', 'validValues' => array('', 'uninstall')));
        if (!$lifecycle->isInstalled()) {
            $page->assignSmartyVariable('availableVersion', EventAttendance::getVersionLabel());
            $page->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
            $page->assignSmartyVariable('installUrl', $plugin->getUrl('index.php') . '?mode=install');
            $page->assignSmartyVariable(
                'resultMessage',
                $result === 'uninstall' ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL_SUCCESS') : ''
            );
            $page->addHtmlByTemplate('plugin.event-attendance.installation.tpl');
        } else {
            $page->assignSmartyVariable('installedVersion', (string) ($plugin->settings()['event_attendance_schema_version'] ?? ''));
            $page->assignSmartyVariable('availableVersion', EventAttendance::getVersionLabel());
            (new UpdateFormPresenter())->create($page, $plugin);
        }
        $page->show();
        exit;
    }

    $mode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'list', 'validValues' => array('list', 'config', 'new', 'save', 'edit', 'save_edit', 'activate', 'deactivate', 'cancel', 'events', 'attendance', 'save_attendance', 'report', 'reports', 'report_pdf', 'report_print', 'report_export', 'my_events', 'save_self_excuses', 'settings', 'save_settings', 'check_update')));
    if (in_array($mode, array('save', 'save_edit', 'activate', 'deactivate', 'cancel', 'save_attendance', 'save_self_excuses'), true)) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    if (in_array($mode, array('settings', 'save_settings', 'check_update'), true)) {
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $settingsService = new LegacySettingsService($gDb);
        if ($mode === 'check_update') {
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
            $page = PagePresenter::withHtmlIDAndHeadline(
                'adm_event_attendance_update_check',
                $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS')
            );
            $page->addTemplateFolder($plugin->templatesDirectory());
            $releaseCheck = SettingsUpdatePresenter::createViewModel(
                (new ReleaseCheckService())->getReleasedVersions(),
                EventAttendance::getVersionLabel(),
                $gL10n
            );
            header('Content-Type: application/json; charset=UTF-8');
            JsonResponsePresenter::send(array('html' => SettingsUpdatePresenter::render($page, $releaseCheck)));
            exit;
        }
        if ($mode === 'save_settings') {
            $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
            $formValues = $form->validate($_POST);
            $settings = $settingsService->get($gCurrentOrgId, $defaults);
            $panel = admFuncVariableIsValid($_GET, 'panel', 'string', array('validValues' => array('access', 'automation', 'rules', 'export')));
            switch ($panel) {
                case 'access':
                    $settings['event_attendance_tracker_manager_roles'] = array_map('intval', $formValues['event_attendance_tracker_manager_roles'] ?? array());
                    break;

                case 'automation':
                    $settings['event_attendance_automation_method'] = in_array($formValues['event_attendance_automation_method'] ?? '', array('disabled', 'cli', 'webcron'), true) ? $formValues['event_attendance_automation_method'] : 'disabled';
                    $settings['event_attendance_webcron_token'] = trim((string) ($formValues['event_attendance_webcron_token'] ?? ''));
                    break;

                case 'rules':
                    foreach (array('event_attendance_members_may_excuse_themselves', 'event_attendance_rate_counts_excused') as $name) {
                        // FormPresenter submits unchecked checkboxes explicitly as "0".
                        // isset() would therefore always evaluate to true here.
                        $settings[$name] = (string) ($formValues[$name] ?? '0') === '1';
                    }
                    foreach (array('event_attendance_self_excuse_deadline_hours', 'event_attendance_rate_decimal_places', 'event_attendance_rate_red_threshold', 'event_attendance_rate_orange_threshold', 'event_attendance_recording_lock_days') as $name) { $settings[$name] = max(0, (int) ($formValues[$name] ?? 0)); }
                    $settings['event_attendance_rate_decimal_places'] = min(4, $settings['event_attendance_rate_decimal_places']);
                    $settings['event_attendance_rate_red_threshold'] = min(100, $settings['event_attendance_rate_red_threshold']);
                    $settings['event_attendance_rate_orange_threshold'] = min(100, max($settings['event_attendance_rate_red_threshold'], $settings['event_attendance_rate_orange_threshold']));
                    break;

                case 'export':
                    $settings['event_attendance_pdf_logo_file'] = trim((string) ($formValues['event_attendance_pdf_logo_file'] ?? ''));
                    $settings['event_attendance_export_filename'] = trim((string) ($formValues['event_attendance_export_filename'] ?? ''));
                    break;
            }
            $settingsService->save($gCurrentOrgId, $settings);
            $lifecycle->synchronizeMenuEntry($gCurrentOrgId, $settings);
            header('Location: ' . $plugin->getUrl('index.php') . '?mode=settings&saved=1');
            exit;
        }
        $settings = $settingsService->get($gCurrentOrgId, $defaults);
        $page = SettingsPagePresenter::create($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS'));
        $settingsSaved = admFuncVariableIsValid($_GET, 'saved', 'string', array('defaultValue' => '', 'validValues' => array('', '1'))) === '1';
        $page->assignSmartyVariable('settingsSaved', $settingsSaved);
        LegacySettingsPresenter::create($page, $plugin, $settings);
        $page->assignSmartyVariable('installedVersion', EventAttendance::getVersionLabel());
        $page->assignSmartyVariable('updateAvailable', $lifecycle->needsUpdate($gCurrentOrgId, $defaults));
        $page->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
        $page->assignSmartyVariable('updateUrl', $plugin->getUrl('index.php') . '?mode=update');
        $page->assignSmartyVariable('uninstallUrl', $plugin->getUrl('index.php') . '?mode=uninstall');
        $page->assignSmartyVariable('checkUpdateUrl', SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'check_update')));
        $page->assignSmartyVariable('pluginDescription', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DESCRIPTION'));
        $page->addHtmlByTemplate('plugin.event-attendance.settings-management.tpl');
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
    $trackers = new TrackerRepository($gDb);
    $recordingAccess = new AttendanceRecordingAccessService($trackers);

    if ($mode === 'save_self_excuses') {
        if (!TrackerAccess::mayExcuseSelf()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        (new SelfExcuseActionService($gDb))->save(
            $gCurrentOrgId,
            (int) $gCurrentUser->getValue('usr_id'),
            $selfExcuseDeadlineHours,
            $formValues['self_excuse_events'] ?? array()
        );
        JsonResponsePresenter::success(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'my_events')));
        exit;
    }

    if ($mode === 'save' || $mode === 'save_edit') {
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        (new TrackerActionService($gDb))->save(
            $gCurrentOrgId,
            (int) $gCurrentUser->getValue('usr_id'),
            $mode,
            $formValues,
            $mode === 'save_edit' ? admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid') : null
        );
        JsonResponsePresenter::success($plugin->getUrl('index.php'));
        exit;
    }

    if ($mode === 'deactivate') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        (new TrackerActionService($gDb))->setActive($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'), false);
        JsonResponsePresenter::success();
        exit;
    }
    if ($mode === 'activate') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        (new TrackerActionService($gDb))->setActive($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'), true);
        JsonResponsePresenter::success();
        exit;
    }
    if ($mode === 'cancel') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        $tracker = $trackers->findByUuid($gCurrentOrgId, admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid'));
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $recordingAccess->assertTrackerIsActive($tracker);
        $eventUuid = admFuncVariableIsValid($_GET, 'event_uuid', 'uuid');
        $recordingAccess->assertRecordingIsOpen($tracker, $eventUuid, $recordingLockDays);
        (new AttendanceActionService($gDb))->cancel((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'));
        JsonResponsePresenter::success();
        exit;
    }

    $trackerUuid = isset($_GET['tracker_uuid']) ? admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid') : '';
    $eventUuid = isset($_GET['event_uuid']) ? admFuncVariableIsValid($_GET, 'event_uuid', 'uuid') : '';
    $eventPeriodFilter = admFuncVariableIsValid($_GET, 'event_period_filter', 'string', array('defaultValue' => 'future', 'validValues' => array('future', 'past', 'all')));
    if ($mode === 'save_attendance') {
        $form = $gCurrentSession->getFormObject((string) ($_POST['adm_csrf_token'] ?? ''));
        $formValues = $form->validate($_POST);
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $recordingAccess->assertTrackerIsActive($tracker);
        $recordingAccess->assertRecordingIsOpen($tracker, $eventUuid, $recordingLockDays);
        if (isset($_POST['adm_button_cancel_event'])) {
            (new AttendanceActionService($gDb))->cancel((int) $tracker['eat_id'], $eventUuid, (int) $gCurrentUser->getValue('usr_id'));
            JsonResponsePresenter::success(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'events', 'tracker_uuid' => $trackerUuid)));
            exit;
        }
        (new AttendanceActionService($gDb))->save(
            (int) $tracker['eat_id'],
            $eventUuid,
            (int) $gCurrentUser->getValue('usr_id'),
            $formValues,
            $gProfileFields
        );
        JsonResponsePresenter::success($plugin->getUrl('index.php'));
        exit;
    }

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
    $reportDateFrom = $parseReportDate('report_date_from');
    $reportDateUntil = $parseReportDate('report_date_until');
    $reportTracker = null;
    if (in_array($mode, array('report', 'report_print', 'report_export', 'report_pdf'), true)) {
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
    }
    if ($mode === 'report_print') {
        $tracker = $reportTracker;
        $printPage = PrintReportPagePresenter::create($plugin, $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORT') . ': ' . $tracker['eat_name']);
        $printPage->addTemplateFile('plugin.event-attendance.report.tpl');
        $printPage->assignSmartyVariable('rows', $reportService->getRows((int) $tracker['eat_id'], $reportPercentageOperator, $reportPercentageValue, $reportDateFrom, $reportDateUntil));
        $printPage->show();
        exit;
    }
    if ($mode === 'report_export') {
        $format = admFuncVariableIsValid($_GET, 'format', 'string', array('validValues' => array('csv', 'xlsx')));
        $tracker = $reportTracker;
        $reportExportService->createExport($tracker, $format, $reportPercentageOperator, $reportPercentageValue, 'L', $reportDateFrom, $reportDateUntil);
        exit;
    }

    if ($mode === 'report_pdf') {
        $tracker = $reportTracker;
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
    $dashboardUrl = $plugin->getUrl('index.php');
    $page = match ($mode) {
        'new', 'edit' => TrackerEditPagePresenter::create($plugin, $headline),
        'my_events' => SelfExcusePagePresenter::create($plugin, $headline),
        'attendance' => AttendancePagePresenter::create($plugin, $headline),
        'report' => ReportPagePresenter::create($plugin, $headline),
        'reports' => ReportsPagePresenter::create($plugin, $headline),
        'events' => EventsPagePresenter::create($plugin, $headline),
        'config' => TrackerConfigurationPagePresenter::create($plugin, $headline),
        default => DashboardPagePresenter::create($plugin, $headline)
    };
    if ($mode === 'list' && $gCurrentUser->isAdministrator()) {
        $settingsUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'settings'));
        // Keep the plugin administration as the first header action, before tracker actions.
        $page->addPageFunctionsMenuItem('adm_event_attendance_plugin_settings', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_SETTINGS'), $settingsUrl, 'bi-gear-fill');
        if ($lifecycle->needsUpdate($gCurrentOrgId, $defaults)) {
            $page->assignSmartyVariable('updateNoticeUrl', $settingsUrl);
            $page->addTemplateFile('plugin.event-attendance.update-notice.tpl');
        }
    }
    if ($mode === 'list' && TrackerAccess::mayManage()) {
        $page->addPageFunctionsMenuItem('adm_event_attendance_configuration', $gL10n->get('SYS_CONFIGURATION'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config')), 'bi-gear-fill');
        $page->addPageFunctionsMenuItem('adm_event_attendance_reports', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_REPORTS'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'reports')), 'bi-bar-chart-fill');
    }
    if ($mode === 'list' && TrackerAccess::mayExcuseSelf()) {
        $page->addPageFunctionsMenuItem('adm_event_attendance_self_excuse', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF'), SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'my_events')), 'bi-person-x-fill');
    }
    switch ($mode) {
    case 'new':
        (new TrackerFormPresenter())->create($page, $plugin);
        break;

    case 'edit':
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $recordingAccess->assertTrackerIsActive($tracker);
        (new TrackerFormPresenter())->create($page, $plugin, $tracker, $trackers->findConfiguration((int) $tracker['eat_id']));
        break;

    case 'my_events':
        if (!TrackerAccess::mayExcuseSelf()) { throw new Exception('SYS_NO_RIGHTS'); }
        (new SelfExcusePresenter())->create($page, $plugin, (new AttendanceRepository($gDb))->findSelfExcusableEvents($gCurrentOrgId, (int) $gCurrentUser->getValue('usr_id'), $selfExcuseDeadlineHours));
        break;

    case 'attendance':
        $tracker = $trackers->findByUuid($gCurrentOrgId, $trackerUuid);
        if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $recordingAccess->assertTrackerIsActive($tracker);
        $occurrences = $trackers->findOccurrences((int) $tracker['eat_id'], 'all');
        $occurrence = array_values(array_filter($occurrences, static fn (array $entry): bool => $entry['dat_uuid'] === $eventUuid))[0] ?? false;
        if ($occurrence === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); }
        $page->setHeadline($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RECORD') . ': ' . $occurrence['dat_headline']);
        $page->addCurrentNavigation();
        (new AttendanceFormPresenter())->create($page, $plugin->getUrl('index.php') . '?mode=save_attendance&tracker_uuid=' . $trackerUuid . '&event_uuid=' . $eventUuid, (new AttendanceRepository($gDb))->findRoster((int) $tracker['eat_id'], $eventUuid, $gProfileFields));
        break;

    case 'report':
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
        break;

    case 'reports':
        $page->addTemplateFile('plugin.event-attendance.reports.tpl');
        $allTrackers = $trackers->findAll($gCurrentOrgId);
        foreach ($allTrackers as &$tracker) {
            $tracker['reportUrl'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'report', 'tracker_uuid' => $tracker['eat_uuid']));
        }
        unset($tracker);
        $page->assignSmartyVariable('trackers', $allTrackers);
        break;

    case 'events':
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
            $occurrence['url'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'attendance', 'tracker_uuid' => $trackerUuid, 'event_uuid' => $occurrence['dat_uuid']));
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
        break;

    default:
        $page->addTemplateFile('plugin.event-attendance.dashboard.tpl');
        $occurrences = $trackers->findActiveOccurrences($gCurrentOrgId, $eventPeriodFilter);
        $trackerFilterValues = array('all' => $gL10n->get('SYS_ALL'));
        $now = new DateTimeImmutable(DATETIME_NOW);
        foreach ($occurrences as &$occurrence) {
            $trackerFilterValues[$occurrence['eat_uuid']] = $occurrence['eat_name'];
            $occurrence['url'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'attendance', 'tracker_uuid' => $occurrence['eat_uuid'], 'event_uuid' => $occurrence['dat_uuid']));
            $occurrence['trackerUuid'] = $occurrence['eat_uuid'];
            $occurrence['uuid'] = $occurrence['eat_uuid'] . '_' . $occurrence['dat_uuid'];
            $occurrence['status'] = $occurrence['cancelled']
                ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED')
                : ($occurrence['eae_snapshot_at'] !== null && new DateTimeImmutable($occurrence['dat_end']) <= $now
                    ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RECORDED')
                    : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DASHBOARD_STATUS_OPEN'));
            $occurrence['hasActions'] = !$occurrence['cancelled'];
            $cancelUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'cancel', 'tracker_uuid' => $occurrence['eat_uuid'], 'event_uuid' => $occurrence['dat_uuid']));
            $occurrence['actions'] = $occurrence['cancelled'] ? array() : array(
                array('url' => $occurrence['url'], 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE')),
                array('dataHref' => 'cancelEventAttendance(' . json_encode('adm_event_attendance_occurrence_' . $occurrence['uuid']) . ', ' . json_encode($cancelUrl) . ', ' . json_encode($gCurrentSession->getCsrfToken()) . ');', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCEL_CONFIRM', array($occurrence['dat_headline'])), 'icon' => 'bi bi-x-circle-fill', 'tooltip' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED'))
            );
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
            $dataTables->createJavascript(count($occurrences), 5);
            $page->addJavascript('$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== "adm_event_attendance_dashboard_table") {
                    return true;
                }
                const trackerUuid = $("#dashboard_tracker_filter").val();
                return trackerUuid === "all" || settings.aoData[dataIndex].nTr.dataset.trackerUuid === trackerUuid;
            });
            $("#dashboard_tracker_filter").on("change", function() {
                $("#adm_event_attendance_dashboard_table").DataTable().draw();
            });
            window.cancelEventAttendance = function(rowId, url, csrfToken) {
                $.post(url, { adm_csrf_token: csrfToken }, function(response) {
                    const result = typeof response === "object" ? response : JSON.parse(response);
                    if (result.status === "success") {
                        $("#adm_modal_messagebox, #adm_modal").modal("hide");
                        window.location.reload();
                    }
                });
            };', true);
        }
        break;
    }
    $page->show();
} catch (Throwable $e) {
    handleException($e, isset($mode) && in_array($mode, array('save', 'save_edit', 'activate', 'deactivate', 'cancel', 'save_attendance', 'save_self_excuses'), true));
}
