<?php

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
    }
}
