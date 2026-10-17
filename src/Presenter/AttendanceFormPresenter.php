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
    }
}
