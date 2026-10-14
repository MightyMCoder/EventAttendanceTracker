<?php
/**
 * Contains the presenter that builds the attendance recording form.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Language;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;

/** Builds attendance-recording and member self-excuse views. */
final class AttendancePresenter
{
    /** Create the attendance recording form for a tracker occurrence. @param array<int,array<string,mixed>> $roster */
    public function create(PagePresenter $page, string $url, array $roster): void
    {
        global $gCurrentSession, $gL10n;

        $form = new FormPresenter(
            'adm_event_attendance_form',
            'plugin.event-attendance.attendance.edit.tpl',
            SecurityUtils::encodeUrl($url),
            $page
        );
        $rows = array();

        foreach ($roster as $person) {
            $field = 'attendance_status_' . (int) $person['eau_usr_id'];
            $statusClass = match ($person['ear_status'] ?? '') {
                'present' => 'text-success border-success',
                'excused' => 'text-warning border-warning',
                'absent' => 'text-danger border-danger',
                default => ''
            };
            $form->addSelectBox($field, '', array(
                '' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNKNOWN'),
                'present' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PRESENT'),
                'excused' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSED'),
                'absent' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ABSENT')
            ), array(
                'defaultValue' => $person['ear_status'] ?? '',
                'showContextDependentFirstEntry' => false,
                'class' => 'adm-event-attendance-status ' . $statusClass
            ));
            $rows[] = array(
                'name' => trim($person['first_name'] . ' ' . $person['last_name']),
                'field' => $field
            );
        }

        $form->addSubmitButton(
            'adm_button_save_attendance',
            $gL10n->get('SYS_SAVE'),
            array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')
        );
        $page->assignSmartyVariable('attendanceRows', $rows);
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
        if (count($rows) > 0) {
            $dataTables = new DataTables($page, 'adm_event_attendance_roster_table');
            $dataTables->disableColumnsSort(array(2));
            $dataTables->createJavascript(count($rows), 2);
        }
        $page->addJavascript('$(".adm-event-attendance-status").on("change", function() {
            $(this).removeClass("text-success border-success text-warning border-warning text-danger border-danger");
            const classes = { present: "text-success border-success", excused: "text-warning border-warning", absent: "text-danger border-danger" };
            if (classes[this.value]) {
                $(this).addClass(classes[this.value]);
            }
        });', true);
    }

    /** Create the page for a member's self-excuse overview. */
    public static function createSelfExcusePage(LegacyPluginContext $plugin, string $headline): EventAttendancePagePresenter
    {
        return EventAttendancePagePresenter::createSelfExcuse($plugin, $headline);
    }

    /** Render the configuration-independent self-excuse event table. @param array<int,array<string,mixed>> $events */
    public static function createSelfExcuseOverview(PagePresenter $page, LegacyPluginContext $plugin, array $events): void
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

        self::addSelfExcuseJavascript($page, $plugin, $gCurrentSession->getCsrfToken(), $gL10n);
    }

    /** Add the Ajax actions for creating and withdrawing self-excuses. */
    private static function addSelfExcuseJavascript(PagePresenter $page, LegacyPluginContext $plugin, string $csrfToken, Language $language): void
    {
        $actionUrl = SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'toggle_self_excuse'));
        $confirmation = json_encode($language->get('PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ALL_CONFIRM'));
        $errorMessage = json_encode($language->get('PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ACTION_FAILED'));
        $page->addJavascript('window.eventAttendanceSelfExcuse = function(eventUuid, trackerUuid, action) {
            const actionUrl = ' . json_encode($actionUrl) . ';
            $.post(actionUrl, { adm_csrf_token: ' . json_encode($csrfToken) . ', event_uuid: eventUuid, tracker_uuid: trackerUuid, scope: action === "withdraw" ? "all" : "single", action: action }, function(data) {
                let result;
                try { result = typeof data === "string" ? JSON.parse(data) : data; } catch (error) { messageBox(' . $errorMessage . ', undefined, "error"); return; }
                if (result.status !== "success") { messageBox(' . $errorMessage . ', undefined, "error"); return; }
                if (result.additionalConfigurations > 0 && action === "excuse") {
                    const message = ' . $confirmation . '.replace("#VAR1#", result.additionalConfigurations);
                    messageBox(message, undefined, undefined, "yes-no", "window.eventAttendanceExcuseAll(" + JSON.stringify(eventUuid) + ");");
                    return;
                }
                window.location.reload();
            }).fail(function() { messageBox(' . $errorMessage . ', undefined, "error"); });
        };
        window.eventAttendanceExcuseAll = function(eventUuid) {
            $.post(' . json_encode($actionUrl) . ', { adm_csrf_token: ' . json_encode($csrfToken) . ', event_uuid: eventUuid, scope: "all", action: "excuse" }, function(data) {
                const result = typeof data === "string" ? JSON.parse(data) : data;
                if (result.status === "success") { window.location.reload(); return; }
                messageBox(' . $errorMessage . ', undefined, "error");
            }).fail(function() { messageBox(' . $errorMessage . ', undefined, "error"); });
        };', true);
    }
}
