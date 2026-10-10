<?php

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;

final class TrackerFormPresenter
{
    /**
     * @param array<string,mixed>|null $tracker
     * @param array{roles: array<int,int>, events: array<int,int>, recurrences: array<int,int>} $configuration
     */
    public function create(PagePresenter $page, Plugin $plugin, ?array $tracker = null, array $configuration = array('roles' => array(), 'events' => array(), 'recurrences' => array())): void
    {
        global $gCurrentOrgId, $gCurrentSession, $gDb, $gL10n;

        $urlParameters = array('mode' => $tracker === null ? 'save' : 'save_edit');
        if ($tracker !== null) {
            $urlParameters['tracker_uuid'] = $tracker['eat_uuid'];
        }
        $form = new FormPresenter('adm_event_attendance_tracker_form', 'plugin.event-attendance.tracker.edit.tpl', SecurityUtils::encodeUrl($plugin->getUrl('index.php'), $urlParameters), $page);
        $form->addInput('tracker_name', $gL10n->get('SYS_NAME'), $tracker['eat_name'] ?? '', array('maxLength' => 100, 'property' => FormPresenter::FIELD_REQUIRED, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_TRACKER_NAME_DESC'));
        $form->addMultilineTextInput('tracker_description', $gL10n->get('SYS_DESCRIPTION'), $tracker['eat_description'] ?? '', 4, array('maxLength' => 1000, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_TRACKER_DESCRIPTION_DESC'));
        $form->addSelectBoxFromSql('tracker_roles', $gL10n->get('SYS_GROUPS_ROLES'), $gDb, array('query' => 'SELECT rol_id, rol_name FROM ' . TBL_ROLES . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id WHERE rol_valid = true AND rol_system = false AND cat_org_id = ? ORDER BY cat_sequence, rol_name', 'params' => array($gCurrentOrgId)), array('defaultValue' => $configuration['roles'], 'multiselect' => true, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_TRACKER_ROLES_DESC'));
        $form->addSelectBoxFromSql('tracker_events', $gL10n->get('SYS_EVENTS'), $gDb, array('query' => 'SELECT dat_id, dat_headline FROM ' . TBL_EVENTS . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = dat_cat_id WHERE cat_org_id = ? AND dat_begin > CURRENT_TIMESTAMP ORDER BY dat_begin', 'params' => array($gCurrentOrgId)), array('defaultValue' => $configuration['events'], 'multiselect' => true, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_TRACKER_EVENTS_DESC'));
        $form->addSelectBoxFromSql('tracker_recurrences', $gL10n->get('SYS_RECURRENCE'), $gDb, array('query' => 'SELECT evr_id, dat_headline FROM ' . TBL_EVENT_RECURRENCES . ' INNER JOIN ' . TBL_EVENTS . ' ON dat_id = evr_dat_id_master INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = dat_cat_id WHERE cat_org_id = ? AND EXISTS (SELECT 1 FROM ' . TBL_EVENTS . ' AS occurrence WHERE occurrence.dat_evr_id = evr_id AND occurrence.dat_begin > CURRENT_TIMESTAMP) ORDER BY dat_begin', 'params' => array($gCurrentOrgId)), array('defaultValue' => $configuration['recurrences'], 'multiselect' => true, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_TRACKER_RECURRENCES_DESC'));
        $form->addSubmitButton('adm_button_save_tracker', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }
}
