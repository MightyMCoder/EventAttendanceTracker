<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;

/** Renders the Admidio 5 settings form with the plugin's Smarty template. */
final class LegacySettingsPresenter
{
    /** @param array<string,mixed> $settings */
    public static function create(PagePresenter $page, LegacyPluginContext $plugin, array $settings): void
    {
        global $gCurrentOrgId, $gCurrentSession, $gDb, $gL10n;

        $form = new FormPresenter(
            'adm_event_attendance_legacy_settings_form',
            'preferences.plugin.event-attendance.tpl',
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'save_settings')),
            $page,
            array('class' => 'form-preferences')
        );
        $form->addSeparator('event_attendance_separator_access', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_ACCESS'));
        $form->addSelectBoxFromSql('event_attendance_tracker_manager_roles', $gL10n->get('SYS_EVENT_ATTENDANCE_TRACKER_MANAGER_ROLES'), $gDb,
            array('query' => 'SELECT rol_id, rol_name FROM ' . TBL_ROLES . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id WHERE cat_org_id = ? AND rol_valid = true AND rol_system = false AND rol_administrator = false AND cat_name_intern <> \'EVENTS\' ORDER BY cat_sequence, rol_name', 'params' => array($gCurrentOrgId)),
            array('defaultValue' => $settings['event_attendance_tracker_manager_roles'], 'multiselect' => true, 'showContextDependentFirstEntry' => false, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_TRACKER_MANAGER_ROLES_DESC'));
        $form->addSeparator('event_attendance_separator_automation', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_AUTOMATION'));
        $form->addSelectBox('event_attendance_automation_method', $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD'), array(
            'disabled' => $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_DISABLED'),
            'cli' => $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_CLI'),
            'webcron' => $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_WEBCRON')
        ), array('defaultValue' => $settings['event_attendance_automation_method'], 'showContextDependentFirstEntry' => false, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_DESC'));
        $form->addInput('event_attendance_webcron_token', 'Web-Cron-Token', (string) $settings['event_attendance_webcron_token'], array('maxLength' => 128));
        $form->addSeparator('event_attendance_separator_rules', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_RULES'));
        $form->addCheckbox('event_attendance_members_may_excuse_themselves', $gL10n->get('SYS_EVENT_ATTENDANCE_EXCUSE_SELF'), (bool) $settings['event_attendance_members_may_excuse_themselves']);
        $form->addInput('event_attendance_self_excuse_deadline_hours', $gL10n->get('SYS_EVENT_ATTENDANCE_SELF_EXCUSE_DEADLINE'), (string) $settings['event_attendance_self_excuse_deadline_hours'], array('type' => 'number', 'minNumber' => 0, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_SELF_EXCUSE_DEADLINE_DESC'));
        $form->addCheckbox('event_attendance_rate_counts_excused', $gL10n->get('SYS_EVENT_ATTENDANCE_RATE_COUNTS_EXCUSED'), (bool) $settings['event_attendance_rate_counts_excused']);
        $form->addInput('event_attendance_rate_decimal_places', $gL10n->get('SYS_EVENT_ATTENDANCE_RATE_DECIMAL_PLACES'), (string) $settings['event_attendance_rate_decimal_places'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 4, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_RATE_DECIMAL_PLACES_DESC'));
        $form->addInput('event_attendance_rate_red_threshold', $gL10n->get('SYS_EVENT_ATTENDANCE_RATE_RED_THRESHOLD'), (string) $settings['event_attendance_rate_red_threshold'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 100, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_RATE_RED_THRESHOLD_DESC'));
        $form->addInput('event_attendance_rate_orange_threshold', $gL10n->get('SYS_EVENT_ATTENDANCE_RATE_ORANGE_THRESHOLD'), (string) $settings['event_attendance_rate_orange_threshold'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 100, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_RATE_ORANGE_THRESHOLD_DESC'));
        $form->addInput('event_attendance_recording_lock_days', $gL10n->get('SYS_EVENT_ATTENDANCE_RECORDING_LOCK_DAYS'), (string) $settings['event_attendance_recording_lock_days'], array('type' => 'number', 'minNumber' => 0, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_RECORDING_LOCK_DAYS_DESC'));
        $form->addSeparator('event_attendance_separator_export', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_EXPORT'));
        $form->addInput('event_attendance_pdf_logo_file', $gL10n->get('SYS_EVENT_ATTENDANCE_PDF_LOGO_FILE'), (string) $settings['event_attendance_pdf_logo_file'], array('maxLength' => 255, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_PDF_LOGO_FILE_DESC'));
        $form->addInput('event_attendance_export_filename', $gL10n->get('SYS_EVENT_ATTENDANCE_EXPORT_FILENAME'), (string) $settings['event_attendance_export_filename'], array('maxLength' => 100, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_EXPORT_FILENAME_DESC'));
        $form->addSubmitButton('adm_button_save_event_attendance', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }
}
