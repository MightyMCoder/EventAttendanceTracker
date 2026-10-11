<?php

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PreferencesPresenter;
use AdmidioPlugin\EventAttendance\EventAttendance;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;

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
        global $gCurrentOrganization, $gCurrentSession, $gDb, $gL10n;

        $plugin = PluginRegistry::requireEnabled(EventAttendance::PLUGIN_ID);
        $settings = $plugin->settings;
        $values = $plugin->getSettingValues();
        $managerRoleIds = array_map('strval', $values['event_attendance_tracker_manager_roles']);

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

        $form->addSeparator('event_attendance_separator_access', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_ACCESS'));
        $form->addSelectBoxFromSql(
            'event_attendance_tracker_manager_roles',
            Language::translateIfTranslationStrId($settings['event_attendance_tracker_manager_roles']['label']),
            $gDb,
            array(
                'query' => 'SELECT rol_id, rol_name
                              FROM ' . TBL_ROLES . '
                        INNER JOIN ' . TBL_CATEGORIES . '
                                ON cat_id = rol_cat_id
                             WHERE cat_org_id = ?
                               AND rol_valid = true
                               AND rol_system = false
                               AND cat_name_intern <> \'EVENTS\'
                          ORDER BY cat_sequence, rol_name',
                'params' => array($gCurrentOrganization->getValue('org_id'))
            ),
            array(
                'defaultValue' => $managerRoleIds,
                'showContextDependentFirstEntry' => false,
                'helpTextId' => $settings['event_attendance_tracker_manager_roles']['description'],
                'multiselect' => true
            )
        );

        $form->addSeparator('event_attendance_separator_attendance', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_ATTENDANCE'));
        $form->addCheckbox(
            'event_attendance_sync_core_cancellation',
            Language::translateIfTranslationStrId($settings['event_attendance_sync_core_cancellation']['label']),
            (bool)$values['event_attendance_sync_core_cancellation'],
            array('helpTextId' => $settings['event_attendance_sync_core_cancellation']['description'])
        );
        $form->addSeparator('event_attendance_separator_automation', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_AUTOMATION'));
        $form->addSelectBox(
            'event_attendance_automation_method',
            Language::translateIfTranslationStrId($settings['event_attendance_automation_method']['label']),
            $settings['event_attendance_automation_method']['valueLabels'],
            array(
                'defaultValue' => (string) $values['event_attendance_automation_method'],
                'showContextDependentFirstEntry' => false,
                'helpTextId' => $settings['event_attendance_automation_method']['description']
            )
        );
        $webcronToken = (string) $values['event_attendance_webcron_token'];
        if ($webcronToken === '') {
            $webcronToken = bin2hex(random_bytes(32));
        }
        $form->addInput('event_attendance_webcron_token', '', $webcronToken, array(
            'type' => 'hidden',
            'property' => FormPresenter::FIELD_HIDDEN
        ));
        $organization = (string) $gCurrentOrganization->getValue('org_shortname');
        $webcronUrl = $plugin->getUrl('webcron.php') . '?organization=' . rawurlencode($organization) . '&token=' . rawurlencode($webcronToken);
        $cliCommand = self::phpCliBinary() . ' ' . ADMIDIO_PATH . '/admidio'
            . ' --organization=' . $organization
            . ' --as=admin'
            . ' event-attendance:close-due-events --quiet';
        $lastRun = (new AttendanceAutomationService($gDb))->getLastRun((int) $gCurrentOrganization->getValue('org_id'));
        $form->addCustomContent(
            'event_attendance_automation_cli',
            $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_CLI'),
            self::automationCommandContent($gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_CLI_HELP'), '*/15 * * * * ' . $cliCommand)
        );
        $form->addCustomContent(
            'event_attendance_automation_webcron',
            $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_WEBCRON'),
            self::automationCommandContent($gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_WEBCRON_HELP'), $webcronUrl)
        );
        $form->addDescription(
            'event_attendance_automation_last_run',
            self::automationLastRunContent($lastRun)
        );

        $form->addSeparator('event_attendance_separator_rules', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_RULES'));
        $form->addCheckbox('event_attendance_members_may_excuse_themselves', Language::translateIfTranslationStrId($settings['event_attendance_members_may_excuse_themselves']['label']), (bool) $values['event_attendance_members_may_excuse_themselves'], array('helpTextId' => $settings['event_attendance_members_may_excuse_themselves']['description']));
        $form->addInput('event_attendance_self_excuse_deadline_hours', Language::translateIfTranslationStrId($settings['event_attendance_self_excuse_deadline_hours']['label']), (string) $values['event_attendance_self_excuse_deadline_hours'], array('type' => 'number', 'minNumber' => 0, 'step' => 1, 'helpTextId' => $settings['event_attendance_self_excuse_deadline_hours']['description']));
        $form->addCheckbox('event_attendance_rate_counts_excused', Language::translateIfTranslationStrId($settings['event_attendance_rate_counts_excused']['label']), (bool) $values['event_attendance_rate_counts_excused'], array('helpTextId' => $settings['event_attendance_rate_counts_excused']['description']));
        $form->addInput('event_attendance_rate_decimal_places', Language::translateIfTranslationStrId($settings['event_attendance_rate_decimal_places']['label']), (string) $values['event_attendance_rate_decimal_places'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 4, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_decimal_places']['description']));
        $form->addInput('event_attendance_rate_red_threshold', Language::translateIfTranslationStrId($settings['event_attendance_rate_red_threshold']['label']), (string) $values['event_attendance_rate_red_threshold'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 100, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_red_threshold']['description']));
        $form->addInput('event_attendance_rate_orange_threshold', Language::translateIfTranslationStrId($settings['event_attendance_rate_orange_threshold']['label']), (string) $values['event_attendance_rate_orange_threshold'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 100, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_orange_threshold']['description']));
        $form->addInput('event_attendance_recording_lock_days', Language::translateIfTranslationStrId($settings['event_attendance_recording_lock_days']['label']), (string) $values['event_attendance_recording_lock_days'], array('type' => 'number', 'minNumber' => 0, 'step' => 1, 'helpTextId' => $settings['event_attendance_recording_lock_days']['description']));
        $form->addSeparator('event_attendance_separator_export', $gL10n->get('SYS_EVENT_ATTENDANCE_SETTINGS_EXPORT'));
        $form->addInput('event_attendance_pdf_logo_file', Language::translateIfTranslationStrId($settings['event_attendance_pdf_logo_file']['label']), (string) $values['event_attendance_pdf_logo_file'], array('maxLength' => 255, 'helpTextId' => $settings['event_attendance_pdf_logo_file']['description']));
        $form->addInput('event_attendance_export_filename', Language::translateIfTranslationStrId($settings['event_attendance_export_filename']['label']), (string) $values['event_attendance_export_filename'], array('maxLength' => 100, 'helpTextId' => $settings['event_attendance_export_filename']['description']));
        $form->addSubmitButton(
            'adm_button_save_event_attendance',
            $gL10n->get('SYS_SAVE'),
            array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')
        );

        $form->addToSmarty($page->getSmartyTemplate());
        $page->getSmartyTemplate()->assign(
            'eventAttendanceManagerRoles',
            $managerRoleIds
        );
        $page->getSmartyTemplate()->assign(
            'eventAttendanceAutomationMethod',
            (string) $values['event_attendance_automation_method']
        );
        $gCurrentSession->addFormObject($form);

        return $plugin->renderTemplate($page, self::TEMPLATE);
    }

    private static function automationCommandContent(string $helpText, string $command): string
    {
        global $gL10n;

        return '<p class="form-text mb-2">' . htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div class="input-group"><code class="form-control overflow-auto" data-automation-command>'
            . htmlspecialchars($command, ENT_QUOTES, 'UTF-8')
            . '</code><button class="btn btn-outline-secondary text-dark" style="color:#212529;background-color:#f8f9fa;border-color:#6c757d;flex:0 0 3rem;width:3rem;" type="button" data-copy-automation-command '
            . 'title="' . htmlspecialchars($gL10n->get('SYS_COPY_CLIPBOARD'), ENT_QUOTES, 'UTF-8')
            . '" aria-label="' . htmlspecialchars($gL10n->get('SYS_COPY_CLIPBOARD'), ENT_QUOTES, 'UTF-8')
            . '"><i class="bi bi-copy" aria-hidden="true"></i></button></div>';
    }

    private static function automationLastRunContent(?string $lastRun): string
    {
        global $gL10n, $gSettingsManager;

        $status = 'danger';
        $statusText = $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS_NEVER');
        $displayValue = $gL10n->get('SYS_NEVER');
        if ($lastRun !== null) {
            $lastRunDate = new \DateTimeImmutable($lastRun);
            $secondsSinceRun = max(0, (new \DateTimeImmutable(DATETIME_NOW))->getTimestamp() - $lastRunDate->getTimestamp());
            $status = $secondsSinceRun <= 3600 ? 'success' : ($secondsSinceRun <= 86400 ? 'warning' : 'danger');
            $statusText = $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS_' . strtoupper($status));
            $displayValue = $lastRunDate->format($gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time'));
        }

        return '<span class="badge text-bg-' . $status . ' me-2"><i class="bi bi-circle-fill" aria-hidden="true"></i> '
            . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '</span>'
            . htmlspecialchars($gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_LAST_RUN', array($displayValue)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * PHP_BINARY identifies the FPM executable for a web request. A cron entry needs the CLI
     * binary, which is conventionally installed in one of these locations.
     */
    private static function phpCliBinary(): string
    {
        foreach (array('/usr/bin/php', '/usr/local/bin/php') as $binary) {
            if (is_executable($binary)) {
                return $binary;
            }
        }

        return 'php';
    }
}
