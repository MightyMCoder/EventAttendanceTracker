<?php
/**
 * Contains the native preferences presentation for the Event Attendance Tracker plugin.
 *
 * @copyright MighryMCoder
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
        $selfExcuseRoleIds = array_map('strval', $values['event_attendance_self_excuse_roles']);

        $accessForm = self::createSettingsForm('access');
        self::addRoleSelect(
            $accessForm,
            'event_attendance_tracker_manager_roles',
            $settings['event_attendance_tracker_manager_roles'],
            $managerRoleIds
        );
        $accessForm->addSubmitButton('adm_button_save_event_attendance_access', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3'));

        $automationForm = self::createSettingsForm('automation');
        $automationForm->addSelectBox(
            'event_attendance_automation_method',
            Language::translateIfTranslationStrId($settings['event_attendance_automation_method']['label']),
            $settings['event_attendance_automation_method']['valueLabels'],
            array(
                'defaultValue' => (string) $values['event_attendance_automation_method'],
                'showContextDependentFirstEntry' => false,
                'helpTextId' => $settings['event_attendance_automation_method']['description']
            )
        );
        $automationForm->addDescription(
            'event_attendance_automation_disabled_notice',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_DISABLED_NOTICE'),
            array('class' => 'alert alert-warning mb-0')
        );
        $webcronToken = (string) $values['event_attendance_webcron_token'];
        if ($webcronToken === '') {
            $webcronToken = bin2hex(random_bytes(32));
        }
        $automationForm->addInput('event_attendance_webcron_token', '', $webcronToken, array(
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
        $automationForm->addCustomContent(
            'event_attendance_automation_cli',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_CLI'),
            $cliCommand === null
                ? self::automationCliUnavailableContent()
                : self::automationCommandContent($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_CLI_HELP'), '*/15 * * * * ' . $cliCommand)
        );
        $automationForm->addCustomContent(
            'event_attendance_automation_webcron',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_WEBCRON'),
            self::automationCommandContent($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_WEBCRON_HELP'), $webcronUrl)
        );
        $automationForm->addDescription(
            'event_attendance_automation_last_run',
            self::automationLastRunContent($lastRun)
        );

        $automationForm->addSubmitButton('adm_button_save_event_attendance_automation', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3'));

        $rulesForm = self::createSettingsForm('rules');
        $rulesForm->addCheckbox('event_attendance_sync_core_cancellation', Language::translateIfTranslationStrId($settings['event_attendance_sync_core_cancellation']['label']), (bool)$values['event_attendance_sync_core_cancellation'], array('helpTextId' => $settings['event_attendance_sync_core_cancellation']['description']));
        $rulesForm->addCheckbox('event_attendance_members_may_excuse_themselves', Language::translateIfTranslationStrId($settings['event_attendance_members_may_excuse_themselves']['label']), (bool)$values['event_attendance_members_may_excuse_themselves'], array('helpTextId' => $settings['event_attendance_members_may_excuse_themselves']['description']));
        self::addRoleSelect($rulesForm, 'event_attendance_self_excuse_roles', $settings['event_attendance_self_excuse_roles'], $selfExcuseRoleIds);
        $rulesForm->addInput('event_attendance_self_excuse_deadline_hours', Language::translateIfTranslationStrId($settings['event_attendance_self_excuse_deadline_hours']['label']), (string)$values['event_attendance_self_excuse_deadline_hours'], array('type' => 'number', 'minNumber' => 0, 'step' => 1, 'helpTextId' => $settings['event_attendance_self_excuse_deadline_hours']['description']));
        $rulesForm->addCheckbox('event_attendance_rate_counts_excused', Language::translateIfTranslationStrId($settings['event_attendance_rate_counts_excused']['label']), (bool)$values['event_attendance_rate_counts_excused'], array('helpTextId' => $settings['event_attendance_rate_counts_excused']['description']));
        $rulesForm->addInput('event_attendance_rate_decimal_places', Language::translateIfTranslationStrId($settings['event_attendance_rate_decimal_places']['label']), (string)$values['event_attendance_rate_decimal_places'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 4, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_decimal_places']['description']));
        $rulesForm->addInput('event_attendance_rate_red_threshold', Language::translateIfTranslationStrId($settings['event_attendance_rate_red_threshold']['label']), (string)$values['event_attendance_rate_red_threshold'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 100, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_red_threshold']['description']));
        $rulesForm->addInput('event_attendance_rate_orange_threshold', Language::translateIfTranslationStrId($settings['event_attendance_rate_orange_threshold']['label']), (string)$values['event_attendance_rate_orange_threshold'], array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 100, 'step' => 1, 'helpTextId' => $settings['event_attendance_rate_orange_threshold']['description']));
        $rulesForm->addInput('event_attendance_recording_lock_days', Language::translateIfTranslationStrId($settings['event_attendance_recording_lock_days']['label']), (string)$values['event_attendance_recording_lock_days'], array('type' => 'number', 'minNumber' => 0, 'step' => 1, 'helpTextId' => $settings['event_attendance_recording_lock_days']['description']));
        $rulesForm->addSubmitButton('adm_button_save_event_attendance_rules', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3'));

        $exportForm = self::createSettingsForm('export');
        $exportForm->addInput('event_attendance_pdf_logo_file', Language::translateIfTranslationStrId($settings['event_attendance_pdf_logo_file']['label']), (string)$values['event_attendance_pdf_logo_file'], array('maxLength' => 255, 'helpTextId' => $settings['event_attendance_pdf_logo_file']['description']));
        $exportForm->addInput('event_attendance_export_filename', Language::translateIfTranslationStrId($settings['event_attendance_export_filename']['label']), (string)$values['event_attendance_export_filename'], array('maxLength' => 100, 'helpTextId' => $settings['event_attendance_export_filename']['description']));
        $exportForm->addSubmitButton('adm_button_save_event_attendance_export', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3'));

        $forms = array('access' => $accessForm, 'automation' => $automationForm, 'rules' => $rulesForm, 'export' => $exportForm);
        $formData = array();
        foreach ($forms as $id => $form) {
            $gCurrentSession->addFormObject($form);
            $formData[$id] = array('attributes' => $form->getAttributes(), 'elements' => $form->getElements());
        }

        $page->getSmartyTemplate()->assign('l10n', $gL10n);
        $page->getSmartyTemplate()->assign('eventAttendanceForms', $formData);
        $page->getSmartyTemplate()->assign(
            'eventAttendanceManagerRoles',
            $managerRoleIds
        );
        $page->getSmartyTemplate()->assign('eventAttendanceSelfExcuseRoles', $selfExcuseRoleIds);
        $page->getSmartyTemplate()->assign(
            'eventAttendanceAutomationMethod',
            (string) $values['event_attendance_automation_method']
        );
        $page->getSmartyTemplate()->assign('eventAttendancePlugin', array(
            'name' => $gL10n->get($plugin->name),
            'description' => $gL10n->get($plugin->description),
            'author' => $plugin->author,
            'homepage' => $plugin->homepage,
            'managerUrl' => ADMIDIO_URL . FOLDER_MODULES . '/plugins.php'
        ));

        return $plugin->renderTemplate($page, self::TEMPLATE);
    }

    /**
     * Create one independent preferences form for an accordion section.
     */
    private static function createSettingsForm(string $section): FormPresenter
    {
        return new FormPresenter(
            'adm_preferences_form_event_attendance_' . $section,
            self::TEMPLATE,
            SecurityUtils::encodeUrl(
                ADMIDIO_URL . FOLDER_MODULES . '/preferences.php',
                array('mode' => 'save', 'panel' => 'event_attendance')
            ),
            null,
            array('class' => 'form-preferences')
        );
    }

    /**
     * Add the role multiselect shared by the access and self-excuse settings.
     *
     * @param array<string,mixed> $setting
     * @param array<int,string> $selectedRoleIds
     */
    private static function addRoleSelect(FormPresenter $form, string $name, array $setting, array $selectedRoleIds): void
    {
        global $gCurrentOrganization, $gDb;

        $form->addSelectBoxFromSql(
            $name,
            Language::translateIfTranslationStrId($setting['label']),
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
                'defaultValue' => $selectedRoleIds,
                'showContextDependentFirstEntry' => false,
                'helpTextId' => $setting['description'],
                'multiselect' => true
            )
        );
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
        $statusText = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_NEVER');
        $displayValue = $gL10n->get('SYS_NEVER');
        if ($lastRun !== null) {
            $lastRunDate = new \DateTimeImmutable($lastRun);
            $secondsSinceRun = max(0, (new \DateTimeImmutable(DATETIME_NOW))->getTimestamp() - $lastRunDate->getTimestamp());
            $status = $secondsSinceRun <= 3600 ? 'success' : ($secondsSinceRun <= 86400 ? 'warning' : 'danger');
            $statusText = $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_' . strtoupper($status));
            $displayValue = $lastRunDate->format($gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time'));
        }

        return '<span class="badge text-bg-' . $status . ' me-2"><i class="bi bi-circle-fill" aria-hidden="true"></i> '
            . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '</span>'
            . htmlspecialchars($gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_LAST_RUN', array($displayValue)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Create the message shown when no usable PHP command-line interpreter is available.
     */
    private static function automationCliUnavailableContent(): string
    {
        global $gL10n;

        return '<p class="alert alert-warning mb-0">'
            . htmlspecialchars(
                $gL10n->get(
                    'PLG_EVENT_ATTENDANCE_TRACKER_CLI_BINARY_NOT_FOUND',
                    array(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION)
                ),
                ENT_QUOTES,
                'UTF-8'
            )
            . '</p>';
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
