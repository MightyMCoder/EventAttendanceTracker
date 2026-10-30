<?php
/**
 * Contains attendance-form presentation for the Event Attendance Tracker plugin.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;

final class AttendanceFormPresenter
{
    /** @param array<int,array<string,mixed>> $roster */
    public function create(PagePresenter $page, string $url, array $roster, ?string $lockToken = null, string $lockHeartbeatUrl = '', int $lockHeartbeatIntervalSeconds = 0, string $lockReturnUrl = ''): void
    {
        global $gCurrentSession, $gL10n;

        $form = new FormPresenter(
            'adm_event_attendance_form',
            'plugin.event-attendance.attendance.edit.tpl',
            SecurityUtils::encodeUrl($url),
            $page
        );
        $form->addInput('attendance_lock_token', '', $lockToken ?? '', array('property' => FormPresenter::FIELD_HIDDEN));
        $defaultExcuses = array_map(static fn (array $person): int => (int) $person['eau_usr_id'], array_values(array_filter(
            $roster, static fn (array $person): bool => $person['ear_status'] === 'excused' && $person['ear_id'] === null
        )));
        $form->addInput('attendance_default_excuses', '', json_encode($defaultExcuses, JSON_THROW_ON_ERROR), array('property' => FormPresenter::FIELD_HIDDEN));
        $form->addInput('attendance_changed_users', '', '[]', array('property' => FormPresenter::FIELD_HIDDEN));
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
            const changedField = document.getElementById("attendance_changed_users");
            const changed = new Set(JSON.parse(changedField.value));
            changed.add(Number(this.id.replace("attendance_status_", "")));
            changedField.value = JSON.stringify(Array.from(changed));
            $(this).removeClass("text-success border-success text-warning border-warning text-danger border-danger");
            const classes = { present: "text-success border-success", excused: "text-warning border-warning", absent: "text-danger border-danger" };
            if (classes[this.value]) {
                $(this).addClass(classes[this.value]);
            }
        });', true);
        $page->addJavascript('$(function() {
            const form = document.getElementById("adm_event_attendance_form");
            const tableElement = document.getElementById("adm_event_attendance_roster_table");
            if (!form || !tableElement || !$.fn.dataTable.isDataTable(tableElement)) {
                return;
            }

            const table = $(tableElement).DataTable();
            form.addEventListener("submit", function() {
                form.querySelectorAll(".adm-event-attendance-hidden-status").forEach(function(input) {
                    input.remove();
                });

                table.rows({ page: "all", search: "none" }).nodes().toArray().forEach(function(row) {
                    row.querySelectorAll("select.adm-event-attendance-status").forEach(function(select) {
                        if (form.contains(select)) {
                            return;
                        }

                        const input = document.createElement("input");
                        input.type = "hidden";
                        input.name = select.name;
                        input.value = select.value;
                        input.className = "adm-event-attendance-hidden-status";
                        form.appendChild(input);
                    });
                });
            }, true);
        });', true);
        if ($lockToken === null) {
            return;
        }

        $heartbeatUrl = json_encode($lockHeartbeatUrl, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG);
        $returnUrl = json_encode($lockReturnUrl, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG);
        $lockLostMessage = json_encode($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCK_LOST'), JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG);
        $page->addJavascript('
            (function () {
                const form = document.getElementById("adm_event_attendance_form");
                const token = document.getElementById("attendance_lock_token");
                if (!form || !token) {
                    return;
                }

                let heartbeatTimer;
                let lockLost = false;
                function heartbeat() {
                    const data = new URLSearchParams();
                    data.set("attendance_lock_token", token.value);
                    fetch(' . $heartbeatUrl . ', {
                        method: "POST",
                        credentials: "same-origin",
                        headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
                        body: data.toString()
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (result) {
                            if (result.status === "success") {
                                return;
                            }
                            if (lockLost) {
                                return;
                            }

                            lockLost = true;
                            window.clearInterval(heartbeatTimer);
                            form.querySelectorAll("input, select, textarea, button").forEach(function (element) {
                                element.disabled = true;
                            });
                            window.alert(' . $lockLostMessage . ');
                            window.location.assign(' . $returnUrl . ');
                        })
                        .catch(function () {
                            // A transient connection failure must not discard unsaved form values.
                        });
                }

                heartbeat();
                heartbeatTimer = window.setInterval(heartbeat, ' . ($lockHeartbeatIntervalSeconds * 1000) . ');
            })();', true);
    }
}
