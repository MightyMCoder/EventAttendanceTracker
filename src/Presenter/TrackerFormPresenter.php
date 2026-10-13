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
        $calendarId = (int) ($configuration['calendar'] ?? 0);
        $source = $calendarId > 0 ? 'calendar' : 'events';
        $form->addSelectBox('tracker_source', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE'), array('events' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_EVENTS', 'calendar' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_CALENDAR'), array('defaultValue' => $source, 'showContextDependentFirstEntry' => false, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE_DESC'));
        // The active source is marked as required dynamically, matching the
        // conditional fields in the Admidio inventory module.
        $form->addSelectBoxFromSql('tracker_events', $gL10n->get('SYS_EVENTS'), $gDb, array('query' => 'SELECT dat_id, dat_headline FROM ' . TBL_EVENTS . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = dat_cat_id WHERE cat_org_id = ? AND dat_begin > CURRENT_TIMESTAMP ORDER BY dat_begin', 'params' => array($gCurrentOrgId)), array('defaultValue' => $configuration['events'], 'multiselect' => true, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_EVENTS_DESC'));
        $form->addSelectBoxFromSql('tracker_calendar', $gL10n->get('SYS_CALENDAR'), $gDb, array('query' => 'SELECT cat_id, cat_name FROM ' . TBL_CATEGORIES . ' WHERE cat_org_id = ? AND cat_type = ? ORDER BY cat_sequence, cat_name', 'params' => array($gCurrentOrgId, 'EVT')), array('defaultValue' => $calendarId > 0 ? (string) $calendarId : '', 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_CALENDAR_DESC'));
        $form->addSubmitButton('adm_button_save_tracker', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3'));

        $calendarRequiredMessage = json_encode(
            strip_tags($gL10n->get('SYS_FIELD_EMPTY', array($gL10n->get('SYS_CALENDAR')))),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG | JSON_THROW_ON_ERROR
        );
        $eventsRequiredMessage = json_encode(
            strip_tags($gL10n->get('SYS_FIELD_EMPTY', array($gL10n->get('SYS_EVENTS')))),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG | JSON_THROW_ON_ERROR
        );
        $page->addJavascript('
            $(function() {
                var trackerSource = document.getElementById("tracker_source");
                var events = document.getElementById("tracker_events");
                var calendar = document.getElementById("tracker_calendar");
                var eventsGroup = document.getElementById("tracker_events_group");
                var calendarGroup = document.getElementById("tracker_calendar_group");
                var calendarRequiredMessage = ' . $calendarRequiredMessage . ';
                var eventsRequiredMessage = ' . $eventsRequiredMessage . ';

                function setRequired(field, group, required) {
                    if (required) {
                        field.setAttribute("required", "required");
                        group.classList.add("admidio-form-group-required");
                    } else {
                        field.removeAttribute("required");
                        group.classList.remove("admidio-form-group-required");
                    }
                }

                function hasSelection(field) {
                    var $field = $(field);
                    // Select2 keeps the selected tags in its own data model on
                    // mobile browsers. Query that model before falling back to
                    // the native select value.
                    if ($field.hasClass("select2-hidden-accessible")) {
                        return $field.select2("data").some(function(entry) {
                            return entry.id !== "" && entry.id !== "placeholder";
                        });
                    }

                    var value = $field.val();
                    if (Array.isArray(value)) {
                        return value.some(function(entry) {
                            return entry !== "" && entry !== "placeholder";
                        });
                    }

                    return value !== null && value !== "" && value !== "placeholder";
                }

                function updateTrackerSource() {
                    var usesCalendar = trackerSource.value === "calendar";
                    eventsGroup.style.display = usesCalendar ? "none" : "";
                    calendarGroup.style.display = usesCalendar ? "" : "none";

                    setRequired(events, eventsGroup, !usesCalendar);
                    setRequired(calendar, calendarGroup, usesCalendar);
                    events.setCustomValidity(!usesCalendar && !hasSelection(events) ? eventsRequiredMessage : "");
                    calendar.setCustomValidity(usesCalendar && !hasSelection(calendar) ? calendarRequiredMessage : "");
                }

                trackerSource.addEventListener("change", updateTrackerSource);
                events.addEventListener("change", updateTrackerSource);
                calendar.addEventListener("change", updateTrackerSource);
                updateTrackerSource();

                // The standard FormPresenter Ajax handler posts FormData directly.
                // Keep it, but run native browser validation before handing
                // this form over to it so dynamically required fields are honoured.
                $("#adm_event_attendance_tracker_form").off("submit", formSubmit).on("submit", function(event) {
                    updateTrackerSource();
                    if (!this.checkValidity()) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        this.reportValidity();
                        return;
                    }

                    event.stopImmediatePropagation();
                    formSubmit.call(this, event);
                });
            });', true);
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }
}
