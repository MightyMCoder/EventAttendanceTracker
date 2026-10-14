<?php
/**
 * Contains the presenter that builds the self-excuse form.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class SelfExcusePresenter
{
    public static function createPage(LegacyPluginContext $plugin, string $headline): EventAttendancePagePresenter
    {
        return EventAttendancePagePresenter::createSelfExcuse($plugin, $headline);
    }

    /** @param array<int,array<string,mixed>> $events */
    public static function createOverview(PagePresenter $page, LegacyPluginContext $plugin, array $events): void
    {
        global $gCurrentSession, $gL10n;

        $groupedEvents = array();
        foreach ($events as $event) {
            $id = $event['dat_uuid'];
            if (!isset($groupedEvents[$id])) {
                $groupedEvents[$id] = array(
                    'uuid' => $id,
                    'trackerUuid' => $event['eat_uuid'],
                    'headline' => $event['dat_headline'],
                    'begin' => $event['dat_begin'],
                    'excused' => true
                );
            }
            $groupedEvents[$id]['excused'] = $groupedEvents[$id]['excused'] && $event['ear_status'] === 'excused';
        }
        foreach ($groupedEvents as &$event) {
            $event['status'] = $event['excused']
                ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSED')
                : $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DASHBOARD_STATUS_OPEN');
            $action = $event['excused'] ? 'withdraw' : 'excuse';
            $event['actions'] = array(array(
                'dataHref' => 'eventAttendanceSelfExcuse(\'' . $event['uuid'] . '\', \'' . $event['trackerUuid'] . '\', \'' . $action . '\');',
                'dataMessage' => $gL10n->get(
                    $action === 'excuse'
                        ? 'PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_CONFIRM'
                        : 'PLG_EVENT_ATTENDANCE_TRACKER_WITHDRAW_SELF_EXCUSE_CONFIRM',
                    array($event['headline'])
                ),
                'icon' => $action === 'excuse' ? 'bi bi-person-x-fill' : 'bi bi-arrow-counterclockwise',
                'tooltip' => $gL10n->get(
                    $action === 'excuse'
                        ? 'PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF'
                        : 'PLG_EVENT_ATTENDANCE_TRACKER_WITHDRAW_SELF_EXCUSE'
                )
            ));
        }
        unset($event);

        $selfExcuseEvents = array_values($groupedEvents);
        $page->assignSmartyVariable('selfExcuseEvents', $selfExcuseEvents);
        $page->addHtmlByTemplate('plugin.event-attendance.self-excuse.tpl');

        if (count($selfExcuseEvents) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_self_excuse_table');
            $dataTables->setOrderColumns(array(array(1, 'asc')));
            $dataTables->disableColumnsSort(array(3));
            $dataTables->createJavascript(count($selfExcuseEvents), 3);
        }

        $actionUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'toggle_self_excuse'));
        $allActionUrl = $actionUrl;
        $confirmation = json_encode($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ALL_CONFIRM'));
        $errorMessage = json_encode($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ACTION_FAILED'));
        $page->addJavascript('window.eventAttendanceSelfExcuse = function(eventUuid, trackerUuid, action) {
            const actionUrl = action === "withdraw" ? ' . json_encode($allActionUrl) . ' : ' . json_encode($actionUrl) . ';
            $.post(actionUrl, {
                // A withdrawal always affects every configuration of this event.
                // An initial excuse is saved for the selected configuration first.
                // The server then reports whether it belongs to further ones.
                adm_csrf_token: ' . json_encode($gCurrentSession->getCsrfToken()) . ',
                event_uuid: eventUuid,
                tracker_uuid: trackerUuid,
                scope: action === "withdraw" ? "all" : "single",
                action: action
            }, function(data) {
                let result;
                try {
                    result = typeof data === "string" ? JSON.parse(data) : data;
                } catch (error) {
                    messageBox(' . $errorMessage . ', undefined, "error");
                    return;
                }
                if (result.status !== "success") {
                    messageBox(' . $errorMessage . ', undefined, "error");
                    return;
                }
                if (result.additionalConfigurations > 0 && action === "excuse") {
                    const message = ' . $confirmation . '.replace("#VAR1#", result.additionalConfigurations);
                    messageBox(message, undefined, undefined, "yes-no", "window.eventAttendanceExcuseAll(" + JSON.stringify(eventUuid) + ");");
                    return;
                }
                window.location.reload();
            }).fail(function() {
                messageBox(' . $errorMessage . ', undefined, "error");
            });
        };
        window.eventAttendanceExcuseAll = function(eventUuid) {
            $.post(' . json_encode($allActionUrl) . ', {
                adm_csrf_token: ' . json_encode($gCurrentSession->getCsrfToken()) . ',
                event_uuid: eventUuid,
                scope: "all",
                action: "excuse"
            }, function(data) {
                const result = typeof data === "string" ? JSON.parse(data) : data;
                if (result.status === "success") {
                    window.location.reload();
                    return;
                }
                messageBox(' . $errorMessage . ', undefined, "error");
            }).fail(function() {
                messageBox(' . $errorMessage . ', undefined, "error");
            });
        };', true);
    }
}
