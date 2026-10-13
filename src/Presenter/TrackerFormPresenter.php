<?php

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class TrackerFormPresenter
{
    /**
     * @param array<string,mixed>|null $tracker
     * @param array{roles: array<int,int>, events: array<int,int>, calendar?: int} $configuration
     */
    public function create(PagePresenter $page, LegacyPluginContext $plugin, ?array $tracker = null, array $configuration = array('roles' => array(), 'events' => array(), 'calendar' => 0)): void
    {
        global $gCurrentOrgId, $gCurrentSession, $gDb, $gL10n;

        $urlParameters = array('mode' => $tracker === null ? 'save' : 'save_edit');
        if ($tracker !== null) {
            $urlParameters['tracker_uuid'] = $tracker['eat_uuid'];
        }
        $form = new FormPresenter('adm_event_attendance_tracker_form', 'plugin.event-attendance.tracker.edit.tpl', SecurityUtils::encodeUrl($plugin->getUrl('index.php'), $urlParameters), $page);
        $form->addInput('tracker_name', $gL10n->get('SYS_NAME'), $tracker['eat_name'] ?? '', array('maxLength' => 100, 'property' => FormPresenter::FIELD_REQUIRED, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_NAME_DESC'));
        $form->addMultilineTextInput('tracker_description', $gL10n->get('SYS_DESCRIPTION'), $tracker['eat_description'] ?? '', 4, array('maxLength' => 1000, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_DESCRIPTION_DESC'));
        $form->addSelectBoxFromSql('tracker_roles', $gL10n->get('SYS_GROUPS_ROLES'), $gDb, array('query' => 'SELECT rol_id, rol_name FROM ' . TBL_ROLES . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id WHERE rol_valid = true AND rol_system = false AND cat_org_id = ? ORDER BY cat_sequence, rol_name', 'params' => array($gCurrentOrgId)), array('defaultValue' => $configuration['roles'], 'multiselect' => true, 'property' => FormPresenter::FIELD_REQUIRED, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_ROLES_DESC'));
        $source = (int) ($configuration['calendar'] ?? 0) > 0 ? 'calendar' : 'events';
        $form->addSelectBox('tracker_source', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE'), array('events' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_EVENTS', 'calendar' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_CALENDAR'), array('defaultValue' => $source, 'showContextDependentFirstEntry' => false, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_DESC'));
        // The active source is required in the browser. Do not mark both fields
        // required in FormPresenter: its server validation cannot know which one
        // JavaScript has disabled.
        $form->addSelectBoxFromSql('tracker_events', $gL10n->get('SYS_EVENTS'), $gDb, array('query' => 'SELECT dat_id, dat_headline FROM ' . TBL_EVENTS . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = dat_cat_id WHERE cat_org_id = ? AND dat_begin > CURRENT_TIMESTAMP ORDER BY dat_begin', 'params' => array($gCurrentOrgId)), array('defaultValue' => $configuration['events'], 'multiselect' => true, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_EVENTS_DESC'));
        $form->addSelectBoxFromSql('tracker_calendar', $gL10n->get('SYS_CALENDAR'), $gDb, array('query' => 'SELECT cat_id, cat_name FROM ' . TBL_CATEGORIES . ' WHERE cat_org_id = ? AND cat_type = ? ORDER BY cat_sequence, cat_name', 'params' => array($gCurrentOrgId, 'EVT')), array('defaultValue' => (string) ((int) ($configuration['calendar'] ?? 0)), 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_CALENDAR_DESC'));
        $form->addSubmitButton('adm_button_save_tracker', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
        $page->addJavascript('function admEventAttendanceToggleSource() {
            const calendar = $("#tracker_source").val() === "calendar";
            const eventsGroup = $("#tracker_events_group");
            const calendarGroup = $("#tracker_calendar_group");
            const events = $("#tracker_events");
            const calendarSelect = $("#tracker_calendar");

            eventsGroup.toggle(!calendar).toggleClass("admidio-form-group-required", !calendar);
            calendarGroup.toggle(calendar).toggleClass("admidio-form-group-required", calendar);
            events.prop({ disabled: calendar, required: !calendar }).attr("aria-required", !calendar);
            calendarSelect.prop({ disabled: !calendar, required: calendar }).attr("aria-required", calendar);
        }
        $("#tracker_source").on("change", admEventAttendanceToggleSource);
        admEventAttendanceToggleSource();', true);
        $page->addJavascript(
            'document.getElementById("adm_event_attendance_tracker_form").addEventListener("submit", function(event) {
                const calendar = $("#tracker_source").val() === "calendar";
                const activeSelect = calendar ? $("#tracker_calendar") : $("#tracker_events");
                const value = activeSelect.val();
                const empty = value === null || value === "" || (Array.isArray(value) && value.length === 0);
                if (!empty) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                activeSelect.attr("aria-invalid", "true");
                const select2Container = activeSelect.next(".select2-container");
                select2Container.find(".select2-selection").addClass("is-invalid");
                const formAlert = $(this).find(".form-alert");
                formAlert.attr("class", "alert alert-danger form-alert")
                    .empty()
                    .append($("<i>").addClass("bi bi-exclamation-circle-fill"))
                    .append(document.createTextNode(" " + ' . json_encode($gL10n->get('SYS_REQUIRED_INPUT')) . '))
                    .show();
                select2Container.find(".select2-selection").trigger("focus");
            }, true);
            $("#tracker_events, #tracker_calendar").on("change select2:select select2:unselect", function() {
                $(this).removeAttr("aria-invalid");
                $(this).next(".select2-container").find(".select2-selection").removeClass("is-invalid");
            });',
            true
        );
    }
}
