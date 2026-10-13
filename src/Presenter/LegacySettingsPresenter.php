<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;
use DateTimeImmutable;

final class LegacySettingsPresenter
{
    /** @param array<string, mixed> $settings */
    public static function create(PagePresenter $page, LegacyPluginContext $plugin, array $settings): void
    {
        global $gCurrentOrgId, $gCurrentOrganization, $gDb, $gL10n;

        $form = self::createForm($page, $plugin, 'access', 'plugin.event-attendance.settings.access.tpl');
        $form->addSelectBoxFromSql(
            'event_attendance_tracker_manager_roles',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_MANAGER_ROLES'),
            $gDb,
            array(
                'query' => 'SELECT rol_id, rol_name
                              FROM ' . TBL_ROLES . '
                        INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
                             WHERE cat_org_id = ?
                               AND rol_valid = true
                               AND rol_system = false
                               AND rol_administrator = false
                               AND cat_name_intern <> \'EVENTS\'
                          ORDER BY cat_sequence, rol_name',
                'params' => array($gCurrentOrgId)
            ),
            array(
                'defaultValue' => $settings['event_attendance_tracker_manager_roles'] ?? array(),
                'multiselect' => true,
                'showContextDependentFirstEntry' => false,
                'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_MANAGER_ROLES_DESC'
            )
        );
        self::finish($form);

        $form = self::createForm($page, $plugin, 'automation', 'plugin.event-attendance.settings.automation.tpl');
        $form->addSelectBox(
            'event_attendance_automation_method',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD'),
            array(
                'disabled' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_DISABLED'),
                'cli' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_CLI'),
                'webcron' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_WEBCRON')
            ),
            array(
                'defaultValue' => $settings['event_attendance_automation_method'] ?? 'disabled',
                'showContextDependentFirstEntry' => false,
                'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_DESC'
            )
        );

        $webcronToken = (string) ($settings['event_attendance_webcron_token'] ?? '');
        if ($webcronToken === '') {
            $webcronToken = bin2hex(random_bytes(32));
        }
        $form->addInput(
            'event_attendance_webcron_token',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_WEBCRON_TOKEN'),
            $webcronToken,
            array('maxLength' => 128, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_WEBCRON_TOKEN_DESC')
        );

        $organization = (string) $gCurrentOrganization->getValue('org_shortname');
        $cliCommand = '/usr/bin/php ' . ADMIDIO_PATH . FOLDER_PLUGINS
            . '/event-attendance/cli.php --organization=' . $organization;
        $automationStatus = self::buildAutomationStatus((new AttendanceAutomationService($gDb))->getLastRun((int) $gCurrentOrgId));
        $page->assignSmartyVariable('automationCliHelp', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_CLI_HELP'));
        $page->assignSmartyVariable('automationCliCommand', '*/15 * * * * ' . $cliCommand);
        $page->assignSmartyVariable('automationWebcronHelp', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_WEBCRON_HELP'));
        $page->assignSmartyVariable(
            'automationWebcronCommand',
            $plugin->getUrl('webcron.php') . '?organization=' . rawurlencode($organization)
                . '&token=' . rawurlencode($webcronToken)
        );
        $page->assignSmartyVariable('automationStatus', $automationStatus);
        $page->assignSmartyVariable('automationCopyLabel', $gL10n->get('SYS_COPY_CLIPBOARD'));
        self::finish($form);

        $form = self::createForm($page, $plugin, 'rules', 'plugin.event-attendance.settings.rules.tpl');
        $form->addCheckbox(
            'event_attendance_members_may_excuse_themselves',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ENABLED'),
            (bool) ($settings['event_attendance_members_may_excuse_themselves'] ?? false),
            array('helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ENABLED_DESC')
        );
        $form->addInput(
            'event_attendance_self_excuse_deadline_hours',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_DEADLINE'),
            (string) ($settings['event_attendance_self_excuse_deadline_hours'] ?? 0),
            array('type' => 'number', 'minNumber' => 0, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_DEADLINE_DESC')
        );
        $form->addCheckbox(
            'event_attendance_rate_counts_excused',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_RATE_COUNTS_EXCUSED'),
            (bool) ($settings['event_attendance_rate_counts_excused'] ?? false),
            array('helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_RATE_COUNTS_EXCUSED_DESC')
        );
        foreach (array(
            'event_attendance_rate_decimal_places' => array('PLG_EVENT_ATTENDANCE_TRACKER_RATE_DECIMAL_PLACES', 4),
            'event_attendance_rate_red_threshold' => array('PLG_EVENT_ATTENDANCE_TRACKER_RATE_RED_THRESHOLD', 100),
            'event_attendance_rate_orange_threshold' => array('PLG_EVENT_ATTENDANCE_TRACKER_RATE_ORANGE_THRESHOLD', 100),
            'event_attendance_recording_lock_days' => array('PLG_EVENT_ATTENDANCE_TRACKER_RECORDING_LOCK_DAYS', null)
        ) as $field => $meta) {
            $options = array('type' => 'number', 'minNumber' => 0, 'helpTextId' => $meta[0] . '_DESC');
            if ($meta[1] !== null) {
                $options['maxNumber'] = $meta[1];
            }
            $form->addInput($field, $gL10n->get($meta[0]), (string) ($settings[$field] ?? 0), $options);
        }
        self::finish($form);
        $page->addJavascript('function admEventAttendanceToggleSelfExcuseSettings() {
            $("#adm_event_attendance_self_excuse_settings").toggle(
                $("#event_attendance_members_may_excuse_themselves").is(":checked")
            );
        }
        $("#event_attendance_members_may_excuse_themselves").on("change", admEventAttendanceToggleSelfExcuseSettings);
        admEventAttendanceToggleSelfExcuseSettings();', true);

        $form = self::createForm($page, $plugin, 'export', 'plugin.event-attendance.settings.export.tpl');
        $form->addInput(
            'event_attendance_pdf_logo_file',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PDF_LOGO_FILE'),
            (string) ($settings['event_attendance_pdf_logo_file'] ?? ''),
            array('maxLength' => 255, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_PDF_LOGO_FILE_DESC')
        );
        $form->addInput(
            'event_attendance_export_filename',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXPORT_FILENAME'),
            (string) ($settings['event_attendance_export_filename'] ?? ''),
            array('maxLength' => 100, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_EXPORT_FILENAME_DESC')
        );
        self::finish($form);
    }

    private static function createForm(
        PagePresenter $page,
        LegacyPluginContext $plugin,
        string $panel,
        string $template
    ): FormPresenter {
        return new FormPresenter(
            'adm_event_attendance_settings_' . $panel,
            $template,
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'save_settings', 'panel' => $panel)),
            $page,
            array('class' => 'form-preferences')
        );
    }

    /** @return array{class:string,text:string,lastRun:string} */
    private static function buildAutomationStatus(?string $lastRun): array
    {
        global $gL10n, $gSettingsManager;

        $status = 'danger';
        $statusText = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_NEVER');
        $lastRunText = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NEVER');

        if ($lastRun !== null) {
            try {
                $lastRunAt = new DateTimeImmutable($lastRun);
                $ageInSeconds = max(0, (new DateTimeImmutable(DATETIME_NOW))->getTimestamp() - $lastRunAt->getTimestamp());
                $status = $ageInSeconds <= 3600 ? 'success' : ($ageInSeconds <= 86400 ? 'warning' : 'danger');
                $statusText = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_' . strtoupper($status));
                $lastRunText = $lastRunAt->format(
                    $gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time')
                );
            } catch (\Exception) {
                // An invalid historic value is displayed as unavailable instead of breaking settings.
            }
        }

        return array(
            'class' => $status,
            'text' => $statusText,
            'lastRun' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_LAST_RUN', array($lastRunText))
        );
    }

    private static function finish(FormPresenter $form): void
    {
        global $gCurrentSession, $gL10n;

        $form->addSubmitButton(
            'adm_button_save_event_attendance',
            $gL10n->get('SYS_SAVE'),
            array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')
        );
        $form->addToHtmlPage(false);
        $gCurrentSession->addFormObject($form);
    }
}
