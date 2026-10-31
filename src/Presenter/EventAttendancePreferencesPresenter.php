<?php
/**
 * Contains the native preferences presentation for the Event Attendance Tracker plugin.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

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
        $recorderRoleIds = array_map('strval', $values['event_attendance_attendance_recorder_roles']);
        $selfExcuseRoleIds = array_map('strval', $values['event_attendance_self_excuse_roles']);

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

        $form->addSeparator('event_attendance_separator_access', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SETTINGS_ACCESS'));
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
                               AND rol_administrator = false
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
        $form->addSelectBoxFromSql(
            'event_attendance_attendance_recorder_roles',
            Language::translateIfTranslationStrId($settings['event_attendance_attendance_recorder_roles']['label']),
            $gDb,
            array(
                'query' => 'SELECT rol_id, rol_name
                              FROM ' . TBL_ROLES . '
                        INNER JOIN ' . TBL_CATEGORIES . '
                                ON cat_id = rol_cat_id
                             WHERE cat_org_id = ?
                               AND rol_valid = true
                               AND rol_system = false
                               AND rol_administrator = false
                               AND cat_name_intern <> \'EVENTS\'
                          ORDER BY cat_sequence, rol_name',
                'params' => array($gCurrentOrganization->getValue('org_id'))
            ),
            array(
                'defaultValue' => $recorderRoleIds,
                'showContextDependentFirstEntry' => false,
                'helpTextId' => $settings['event_attendance_attendance_recorder_roles']['description'],
                'multiselect' => true
            )
        );

        $form->addSeparator('event_attendance_separator_attendance', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SETTINGS_ATTENDANCE'));
        $form->addCheckbox(
            'event_attendance_sync_core_cancellation',
            Language::translateIfTranslationStrId($settings['event_attendance_sync_core_cancellation']['label']),
            (bool)$values['event_attendance_sync_core_cancellation'],
            array('helpTextId' => $settings['event_attendance_sync_core_cancellation']['description'])
        );
        $form->addCheckbox(
            'event_attendance_attendance_lock_enabled',
            Language::translateIfTranslationStrId($settings['event_attendance_attendance_lock_enabled']['label']),
            (bool) $values['event_attendance_attendance_lock_enabled'],
            array('helpTextId' => $settings['event_attendance_attendance_lock_enabled']['description'])
        );
        $form->addInput(
            'event_attendance_attendance_lock_duration_minutes',
            Language::translateIfTranslationStrId($settings['event_attendance_attendance_lock_duration_minutes']['label']),
            (string) $values['event_attendance_attendance_lock_duration_minutes'],
            array('type' => 'number', 'minNumber' => 1, 'maxNumber' => 1440, 'step' => 1, 'helpTextId' => $settings['event_attendance_attendance_lock_duration_minutes']['description'])
        );
        $form->addSeparator('event_attendance_separator_automation', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SETTINGS_AUTOMATION'));
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
        $cliBinary = self::findCliBinary();
        $cliCommand = $cliBinary === null ? null : $cliBinary . ' ' . ADMIDIO_PATH . '/admidio'
            . ' --organization=' . $organization
            . ' --as=admin'
            . ' event-attendance:close-due-events --quiet';
        $lastRun = (new AttendanceAutomationService($gDb))->getLastRun((int) $gCurrentOrganization->getValue('org_id'));

        $form->addSeparator('event_attendance_separator_rules', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SETTINGS_RULES'));
        $form->addCheckbox('event_attendance_members_may_excuse_themselves', Language::translateIfTranslationStrId($settings['event_attendance_members_may_excuse_themselves']['label']), (bool) $values['event_attendance_members_may_excuse_themselves'], array('helpTextId' => $settings['event_attendance_members_may_excuse_themselves']['description']));
        $form->addSelectBoxFromSql(
            'event_attendance_self_excuse_roles',
            Language::translateIfTranslationStrId($settings['event_attendance_self_excuse_roles']['label']),
            $gDb,
            array(
                'query' => 'SELECT rol_id, rol_name
                              FROM ' . TBL_ROLES . '
                        INNER JOIN ' . TBL_CATEGORIES . '
                                ON cat_id = rol_cat_id
                             WHERE cat_org_id = ?
                               AND rol_valid = true
                               AND rol_system = false
                               AND rol_administrator = false
                               AND cat_name_intern <> \'EVENTS\'
                          ORDER BY cat_sequence, rol_name',
                'params' => array($gCurrentOrganization->getValue('org_id'))
            ),
            array(
                'defaultValue' => $selfExcuseRoleIds,
                'showContextDependentFirstEntry' => false,
                'helpTextId' => $settings['event_attendance_self_excuse_roles']['description'],
                'multiselect' => true
            )
        );
        $form->addInput('event_attendance_self_excuse_deadline_hours', Language::translateIfTranslationStrId($settings['event_attendance_self_excuse_deadline_hours']['label']), (string) $values['event_attendance_self_excuse_deadline_hours'], array('type' => 'number', 'minNumber' => 0, 'step' => 1, 'helpTextId' => $settings['event_attendance_self_excuse_deadline_hours']['description']));
        $form->addCheckbox('event_attendance_rate_counts_excused', Language::translateIfTranslationStrId($settings['event_attendance_rate_counts_excused']['label']), (bool) $values['event_attendance_rate_counts_excused'], array('helpTextId' => $settings['event_attendance_rate_counts_excused']['description']));
        $form->addInput('event_attendance_rate_decimal_places', Language::translateIfTranslationStrId($settings['event_attendance_rate_decimal_places']['label']), (string) $values['event_attendance_rate_decimal_places'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 4, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_decimal_places']['description']));
        $form->addInput('event_attendance_rate_red_threshold', Language::translateIfTranslationStrId($settings['event_attendance_rate_red_threshold']['label']), (string) $values['event_attendance_rate_red_threshold'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 100, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_red_threshold']['description']));
        $form->addInput('event_attendance_rate_orange_threshold', Language::translateIfTranslationStrId($settings['event_attendance_rate_orange_threshold']['label']), (string) $values['event_attendance_rate_orange_threshold'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 100, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_orange_threshold']['description']));
        $form->addInput('event_attendance_recording_lock_days', Language::translateIfTranslationStrId($settings['event_attendance_recording_lock_days']['label']), (string) $values['event_attendance_recording_lock_days'], array('type' => 'number', 'minNumber' => 0, 'step' => 1, 'helpTextId' => $settings['event_attendance_recording_lock_days']['description']));
        $form->addSeparator('event_attendance_separator_export', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SETTINGS_EXPORT'));
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
        $page->getSmartyTemplate()->assign('eventAttendanceRecorderRoles', $recorderRoleIds);
        $page->getSmartyTemplate()->assign('eventAttendanceSelfExcuseRoles', $selfExcuseRoleIds);
        $page->getSmartyTemplate()->assign(
            'eventAttendanceAutomationMethod',
            (string) $values['event_attendance_automation_method']
        );
        $page->getSmartyTemplate()->assign(array(
            'eventAttendanceAutomationDisabledNotice' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_DISABLED_NOTICE'),
            'eventAttendanceAutomationCliLabel' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_CLI'),
            'eventAttendanceAutomationCliHelp' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_CLI_HELP'),
            'eventAttendanceAutomationCliCommand' => $cliCommand === null ? '' : '*/15 * * * * ' . $cliCommand,
            'eventAttendanceAutomationCliUnavailable' => $cliCommand === null
                ? $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CLI_BINARY_NOT_FOUND', array(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION))
                : '',
            'eventAttendanceAutomationWebcronLabel' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_WEBCRON'),
            'eventAttendanceAutomationWebcronHelp' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_WEBCRON_HELP'),
            'eventAttendanceAutomationWebcronUrl' => $webcronUrl,
            'eventAttendanceAutomationLastRun' => self::automationLastRunData($lastRun),
            'eventAttendanceCopyLabel' => $gL10n->get('SYS_COPY_CLIPBOARD'),
            'eventAttendanceLockEnabled' => (bool) $values['event_attendance_attendance_lock_enabled'],
            'eventAttendanceClearLocksUrl' => $plugin->getUrl('index.php') . '?mode=clear_attendance_locks',
            'eventAttendanceCsrfToken' => $gCurrentSession->getCsrfToken()
        ));
        $gCurrentSession->addFormObject($form);

        return $plugin->renderTemplate($page, self::TEMPLATE);
    }

    /**
     * Prepare the translated status data used by the automation status template.
     * @return array{status: string, statusText: string, lastRunText: string}
     */
    private static function automationLastRunData(?string $lastRun): array
    {
        global $gL10n, $gSettingsManager;

        $status = 'danger';
        $statusText = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_NEVER');
        $displayValue = $gL10n->get('SYS_NEVER');
        if ($lastRun !== null) {
            $lastRunDate = new \DateTimeImmutable($lastRun);
            $secondsSinceRun = max(0, (new \DateTimeImmutable(DATETIME_NOW))->getTimestamp() - $lastRunDate->getTimestamp());
            $status = $secondsSinceRun <= 3600 ? 'success' : ($secondsSinceRun <= 86400 ? 'warning' : 'danger');
            $statusText = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_' . strtoupper($status));
            $displayValue = $lastRunDate->format($gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time'));
        }

        return array(
            'status' => $status,
            'statusText' => $statusText,
            'lastRunText' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_LAST_RUN', array($displayValue))
        );
    }

    /**
     * Find a usable PHP CLI program in the usual hosting locations.
     *
     * A file name alone is not enough because some hosts expose a CGI executable under a
     * PHP-looking path. Each candidate is therefore started with a harmless PHP_SAPI query.
     */
    private static function findCliBinary(): ?string
    {
        foreach (self::getCliBinaryCandidates() as $candidate) {
            if (is_file($candidate) && is_executable($candidate) && self::isCliBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Return likely CLI locations for the PHP version that serves Admidio.
     *
     * @return array<int,string>
     */
    private static function getCliBinaryCandidates(): array
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        return array_values(array_unique(array(
            '/usr/bin/php' . $version . '-cli',
            '/usr/local/bin/php' . $version . '-cli',
            '/usr/bin/php' . $version,
            '/usr/local/bin/php' . $version,
            '/opt/plesk/php/' . $version . '/bin/php',
            '/opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php'
        )));
    }

    /**
     * Verify that an executable uses PHP's command-line SAPI.
     */
    private static function isCliBinary(string $binary): bool
    {
        if (!function_exists('proc_open')) {
            return str_ends_with($binary, '-cli');
        }

        $pipes = array();
        $process = @proc_open(
            array($binary, '-r', 'echo PHP_SAPI . ":" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        if (!is_resource($process)) {
            return false;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0
            && trim($output) === 'cli:' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }
}
