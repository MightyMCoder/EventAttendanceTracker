<?php
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\AttendanceFormPresenter;
use AdmidioPlugin\EventAttendance\Presenter\TrackerFormPresenter;
use AdmidioPlugin\EventAttendance\Repository\AttendanceRepository;
use AdmidioPlugin\EventAttendance\Service\AttendanceService;
use AdmidioPlugin\EventAttendance\Service\AttendanceSnapshotService;
use AdmidioPlugin\EventAttendance\Service\CancellationService;
use AdmidioPlugin\EventAttendance\Repository\TrackerRepository;
use AdmidioPlugin\EventAttendance\Service\TrackerAccess;
use AdmidioPlugin\EventAttendance\Service\TrackerService;
try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');
    $mode = admFuncVariableIsValid($_GET, 'mode', 'string', ['defaultValue' => 'list', 'validValues' => ['list', 'new', 'save', 'events', 'attendance', 'save_attendance', 'cancel']]);
    if (!TrackerAccess::mayManage()) { throw new Exception('SYS_NO_RIGHTS'); }
    $plugin = PluginRegistry::requireEnabled('event-attendance');
    if ($mode === 'save') {
        SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? '');
        (new TrackerService($gDb))->create($gCurrentOrgId, (int)$gCurrentUser->getValue('usr_id'), admFuncVariableIsValid($_POST, 'tracker_name', 'string'), admFuncVariableIsValid($_POST, 'tracker_description', 'string'), admFuncVariableIsValid($_POST, 'tracker_roles', 'array', ['defaultValue' => []]), admFuncVariableIsValid($_POST, 'tracker_events', 'array', ['defaultValue' => []]), admFuncVariableIsValid($_POST, 'tracker_recurrences', 'array', ['defaultValue' => []]));
        admRedirect($plugin->getUrl('index.php'));
    }
    $trackerUuid = isset($_GET['tracker_uuid']) ? admFuncVariableIsValid($_GET, 'tracker_uuid', 'uuid') : '';
    $eventUuid = isset($_GET['event_uuid']) ? admFuncVariableIsValid($_GET, 'event_uuid', 'uuid') : '';
    $trackerRepository = new TrackerRepository($gDb);
    if ($mode === 'cancel') { SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? ''); $tracker = $trackerRepository->findByUuid($gCurrentOrgId, $trackerUuid); if ($tracker === false) throw new Exception('SYS_INVALID_PAGE_VIEW'); (new CancellationService($gDb))->cancel((int)$tracker['eat_id'], $eventUuid, (int)$gCurrentUser->getValue('usr_id')); admRedirect(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), ['mode' => 'events', 'tracker_uuid' => $trackerUuid])); }
    if ($mode === 'save_attendance') { SecurityUtils::validateCsrfToken($_POST['adm_csrf_token'] ?? ''); $tracker = $trackerRepository->findByUuid($gCurrentOrgId, $trackerUuid); if ($tracker === false) throw new Exception('SYS_INVALID_PAGE_VIEW'); (new AttendanceSnapshotService($gDb))->closeDueEvents(new DateTimeImmutable(DATETIME_NOW)); $roster = (new AttendanceRepository($gDb))->findRoster((int)$tracker['eat_id'], $eventUuid, $gProfileFields); $statuses = []; foreach ($roster as $person) { $key = 'attendance_status_' . (int)$person['eau_usr_id']; $statuses[(int)$person['eau_usr_id']] = admFuncVariableIsValid($_POST, $key, 'string'); } (new AttendanceService($gDb))->save((int)$tracker['eat_id'], $eventUuid, (int)$gCurrentUser->getValue('usr_id'), $statuses); admRedirect(SecurityUtils::encodeUrl($plugin->getUrl('index.php'), ['mode' => 'attendance', 'tracker_uuid' => $trackerUuid, 'event_uuid' => $eventUuid])); }
    $page = PagePresenter::withHtmlIDAndHeadline('adm_event_attendance', $mode === 'new' ? $gL10n->get('SYS_CREATE') : $gL10n->get('SYS_EVENT_ATTENDANCE_TRACKERS'));
    $gNavigation->addStartUrl(CURRENT_URL, $page->getHeadline(), $plugin->icon);
    $page->addTemplateFolder($plugin->getDirectory(Plugin::DIR_TEMPLATES));
    if ($mode === 'new') { (new TrackerFormPresenter())->create($page, $plugin); } elseif ($mode === 'attendance') { $tracker = $trackerRepository->findByUuid($gCurrentOrgId, $trackerUuid); if ($tracker === false) throw new Exception('SYS_INVALID_PAGE_VIEW'); (new AttendanceSnapshotService($gDb))->closeDueEvents(new DateTimeImmutable(DATETIME_NOW)); (new AttendanceFormPresenter())->create($page, $plugin->getUrl('index.php') . '?mode=save_attendance&tracker_uuid=' . $trackerUuid . '&event_uuid=' . $eventUuid, $plugin->getUrl('index.php') . '?mode=cancel&tracker_uuid=' . $trackerUuid . '&event_uuid=' . $eventUuid, (new AttendanceRepository($gDb))->findRoster((int)$tracker['eat_id'], $eventUuid, $gProfileFields)); } elseif ($mode === 'events') { $tracker = $trackerRepository->findByUuid($gCurrentOrgId, $trackerUuid); if ($tracker === false) { throw new Exception('SYS_INVALID_PAGE_VIEW'); } $page->setHeadline($tracker['eat_name']); $page->addTemplateFile('plugin.event-attendance.events.tpl'); $occurrences = $trackerRepository->findOccurrences((int)$tracker['eat_id']); foreach ($occurrences as &$occurrence) { $occurrence['url'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), ['mode' => 'attendance', 'tracker_uuid' => $trackerUuid, 'event_uuid' => $occurrence['dat_uuid']]); } unset($occurrence); $page->assignSmartyVariable('occurrences', $occurrences); } else { $page->addTemplateFile('plugin.event-attendance.trackers.tpl'); $trackers = $trackerRepository->findAll($gCurrentOrgId); foreach ($trackers as &$tracker) { $tracker['url'] = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), ['mode' => 'events', 'tracker_uuid' => $tracker['eat_uuid']]); } unset($tracker); $page->assignSmartyVariable('trackers', $trackers); $page->assignSmartyVariable('createUrl', SecurityUtils::encodeUrl($plugin->getUrl('index.php'), ['mode' => 'new'])); }
    $page->show();
} catch (Throwable $e) { handleException($e); }
