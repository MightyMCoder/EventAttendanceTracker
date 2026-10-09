<?php

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Roles\Service\RolesService;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PreferencesPresenter;
use AdmidioPlugin\EventAttendance\EventAttendance;

/**
 * Preferences that require a roles multiselect instead of the generated array text field.
 */
final class EventAttendancePreferencesPresenter
{
    private const TEMPLATE = 'preferences.plugin.event-attendance.tpl';

    private function __construct()
    {
    }

    /**
     * Build the event-attendance preferences panel.
     * @throws Exception|\Smarty\Exception
     */
    public static function createForm(PreferencesPresenter $page): string
    {
        global $gCurrentSession, $gDb, $gL10n;

        $plugin = PluginRegistry::requireEnabled(EventAttendance::PLUGIN_ID);
        $settings = $plugin->settings;
        $values = $plugin->getSettingValues();

        $form = new FormPresenter(
            'adm_preferences_form_event_attendance',
            self::TEMPLATE,
            SecurityUtils::encodeUrl(
                ADMIDIO_URL . FOLDER_MODULES . '/preferences.php',
                array('mode' => 'save', 'panel' => 'event_attendance')
            ),
            null,
            array('class' => 'form-preferences')
        );

        $form->addCheckbox(
            'event_attendance_members_may_excuse_themselves',
            Language::translateIfTranslationStrId($settings['event_attendance_members_may_excuse_themselves']['label']),
            (bool)$values['event_attendance_members_may_excuse_themselves'],
            array('helpTextId' => $settings['event_attendance_members_may_excuse_themselves']['description'])
        );

        $roles = array();
        foreach ((new RolesService($gDb))->findAll() as $role) {
            $roles[(string)$role['rol_id']] = $role['rol_name'];
        }
        $form->addSelectBox(
            'event_attendance_tracker_manager_roles',
            Language::translateIfTranslationStrId($settings['event_attendance_tracker_manager_roles']['label']),
            $roles,
            array(
                'defaultValue' => $values['event_attendance_tracker_manager_roles'],
                'showContextDependentFirstEntry' => false,
                'helpTextId' => $settings['event_attendance_tracker_manager_roles']['description'],
                'multiselect' => true,
                'maximumSelectionNumber' => count($roles)
            )
        );

        $form->addCheckbox(
            'event_attendance_sync_core_cancellation',
            Language::translateIfTranslationStrId($settings['event_attendance_sync_core_cancellation']['label']),
            (bool)$values['event_attendance_sync_core_cancellation'],
            array('helpTextId' => $settings['event_attendance_sync_core_cancellation']['description'])
        );
        $form->addSubmitButton(
            'adm_button_save_event_attendance',
            $gL10n->get('SYS_SAVE'),
            array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')
        );

        $form->addToSmarty($page->getSmartyTemplate());
        $gCurrentSession->addFormObject($form);

        return $plugin->renderTemplate($page, self::TEMPLATE);
    }
}
