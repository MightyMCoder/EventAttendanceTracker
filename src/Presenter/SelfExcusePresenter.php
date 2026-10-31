<?php
/**
 * Contains the self-excuse overview presenter.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\PagePresenter;

/**
 * Prepares the configuration-independent self-excuse event table.
 */
final class SelfExcusePresenter
{
    /** @param array<int,array<string,mixed>> $events */
    public function create(PagePresenter $page, Plugin $plugin, array $events): void
    {
        global $gCurrentSession, $gL10n;

        $groupedEvents = array();
        foreach ($events as $event) {
            $uuid = $event['dat_uuid'];
            if (!isset($groupedEvents[$uuid])) {
                $groupedEvents[$uuid] = array('uuid' => $uuid, 'trackerUuid' => $event['eat_uuid'], 'headline' => $event['dat_headline'], 'begin' => $event['dat_begin'], 'excused' => true, 'permanentExcuse' => true);
            }
            $groupedEvents[$uuid]['excused'] = $groupedEvents[$uuid]['excused'] && $event['ear_status'] === 'excused';
            $groupedEvents[$uuid]['permanentExcuse'] = $groupedEvents[$uuid]['permanentExcuse'] && (bool) $event['permanent_excuse'];
            if (!(bool) $event['permanent_excuse']) {
                $groupedEvents[$uuid]['trackerUuid'] = $event['eat_uuid'];
            }
        }
        foreach ($groupedEvents as &$event) {
            if ($event['excused'] && $event['permanentExcuse']) {
                $event['status'] = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSED');
                $event['actions'] = array();
                continue;
            }
            $action = $event['excused'] ? 'withdraw' : 'excuse';
            $event['status'] = $event['excused'] ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSED') : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNKNOWN');
            $event['actions'] = array(array('dataHref' => 'eventAttendanceSelfExcuse(' . json_encode($event['uuid']) . ', ' . json_encode($event['trackerUuid']) . ', ' . json_encode($action) . ');', 'dataMessage' => $event['headline'], 'icon' => $action === 'excuse' ? 'bi bi-person-x-fill' : 'bi bi-arrow-counterclockwise', 'tooltip' => $gL10n->get($action === 'excuse' ? 'PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF' : 'PLG_EVENT_ATTENDANCE_TRACKER_WITHDRAW_SELF_EXCUSE')));
        }
        unset($event);

        $page->addTemplateFile('plugin.event-attendance.self-excuse.tpl');
        $page->assignSmartyVariable('selfExcuseEvents', array_values($groupedEvents));
        if ($groupedEvents !== array()) {
            $dataTables = new DataTables($page, 'adm_event_attendance_self_excuse_table');
            $dataTables->setOrderColumns(array(array(1, 'asc')));
            $dataTables->disableColumnsSort(array(3));
            $dataTables->createJavascript(count($groupedEvents), 3);
        }

        $actionUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'toggle_self_excuse'));
        $page->addJavascript('window.eventAttendanceSelfExcuse = function(eventUuid, trackerUuid, action) { $.post(' . json_encode($actionUrl) . ', { adm_csrf_token: ' . json_encode($gCurrentSession->getCsrfToken()) . ', event_uuid: eventUuid, tracker_uuid: trackerUuid, scope: action === "withdraw" ? "all" : "single", action: action }, function(result) { if (result.status !== "success") { return; } if (result.additionalConfigurations > 0 && action === "excuse") { messageBox(' . json_encode($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ALL_CONFIRM')) . '.replace("#VAR1#", result.additionalConfigurations), undefined, undefined, "yes-no", "window.eventAttendanceExcuseAll(" + JSON.stringify(eventUuid) + ");"); return; } window.location.reload(); }); }; window.eventAttendanceExcuseAll = function(eventUuid) { $.post(' . json_encode($actionUrl) . ', { adm_csrf_token: ' . json_encode($gCurrentSession->getCsrfToken()) . ', event_uuid: eventUuid, scope: "all", action: "excuse" }, function(result) { if (result.status === "success") { window.location.reload(); } }); };', true);
    }
}
