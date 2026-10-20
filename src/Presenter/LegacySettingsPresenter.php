<?php
/**
 * Contains the presenter that builds the Admidio 5 plugin settings forms.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use DateTimeImmutable;

final class LegacySettingsPresenter
{
    /**
     * Create the complete legacy plugin settings page.
     *
     * Each accordion uses its own form so unrelated settings are never submitted
     * accidentally when an administrator saves one settings section.
     *
     * @param array<string,mixed> $settings
     * @return void
     */
    public static function create(
        PagePresenter $page,
        LegacyPluginContext $plugin,
        array $settings,
        ?string $automationLastRun
    ): void
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
        $form->addSelectBoxFromSql(
            'event_attendance_attendance_recorder_roles',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_RECORDER_ROLES'),
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
                'defaultValue' => $settings['event_attendance_attendance_recorder_roles'] ?? array(),
                'multiselect' => true,
                'showContextDependentFirstEntry' => false,
                'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_RECORDER_ROLES_DESC'
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
        $cliBinary = self::findCliBinary();
        $cliCommand = $cliBinary === null ? '' : $cliBinary . ' ' . ADMIDIO_PATH . FOLDER_PLUGINS
            . '/event-attendance/cli.php --organization=' . $organization;
        $automationStatus = self::buildAutomationStatus($automationLastRun);
        $page->assignSmartyVariable('automationCliHelp', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_CLI_HELP'));
        $page->assignSmartyVariable('automationCliCommand', $cliCommand === '' ? '' : '*/15 * * * * ' . $cliCommand);
        $page->assignSmartyVariable(
            'automationCliUnavailableMessage',
            $gL10n->get(
                'PLG_EVENT_ATTENDANCE_TRACKER_CLI_BINARY_NOT_FOUND',
                array(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION)
            )
        );
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
        $form->addSelectBoxFromSql(
            'event_attendance_self_excuse_roles',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ROLES'),
            $gDb,
            array('query' => 'SELECT rol_id, rol_name FROM ' . TBL_ROLES . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id WHERE cat_org_id = ? AND rol_valid = true AND rol_system = false AND rol_administrator = false AND cat_name_intern <> \'EVENTS\' ORDER BY cat_sequence, rol_name', 'params' => array($gCurrentOrgId)),
            array('defaultValue' => $settings['event_attendance_self_excuse_roles'] ?? array(), 'multiselect' => true, 'showContextDependentFirstEntry' => false, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_ROLES_DESC')
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
        $form = self::createForm($page, $plugin, 'locks', 'plugin.event-attendance.settings.locks.tpl');
        $form->addCheckbox(
            'event_attendance_attendance_lock_enabled',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCK_ENABLED'),
            (bool) ($settings['event_attendance_attendance_lock_enabled'] ?? false),
            array('helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCK_ENABLED_DESC')
        );
        $form->addInput(
            'event_attendance_attendance_lock_duration_minutes',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCK_DURATION'),
            (string) ($settings['event_attendance_attendance_lock_duration_minutes'] ?? 5),
            array('type' => 'number', 'minNumber' => 1, 'maxNumber' => 1440, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCK_DURATION_DESC')
        );
        self::finish($form);
        $page->addJavascript('function admEventAttendanceToggleLockSettings() {
            $("#adm_event_attendance_lock_settings").toggle(
                $("#event_attendance_attendance_lock_enabled").is(":checked")
            );
        }
        $("#event_attendance_attendance_lock_enabled").on("change", admEventAttendanceToggleLockSettings);
        admEventAttendanceToggleLockSettings();', true);

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

    /**
     * Find a usable PHP CLI program in the usual hosting locations.
     *
     * A file name alone is not enough: several hosts expose a CGI executable
     * under a PHP-looking path. Every candidate is therefore started with a
     * harmless PHP_SAPI query before it is offered as a cron command.
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
     * @return array<int, string>
     */
    private static function getCliBinaryCandidates(): array
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $candidates = array(
            '/usr/bin/php' . $version . '-cli',
            '/usr/local/bin/php' . $version . '-cli',
            '/usr/bin/php' . $version,
            '/usr/local/bin/php' . $version,
            '/opt/plesk/php/' . $version . '/bin/php',
            '/opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php'
        );

        return array_values(array_unique($candidates));
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

    /**
     * Create one settings-panel form with the shared Admidio configuration.
     */
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

    /**
     * Build the display state of the automatic-processing status indicator.
     *
     * @return array{class:string,text:string,lastRun:string}
     */
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

    /**
     * Add the standard save button and register a settings form in the session.
     */
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

    /**
     * Build the view model for the asynchronous plugin update check.
     *
     * @param array{connected:bool,stable:array{version:string,url:string}|null,beta:array{version:string,url:string}|null} $releases
     * @return array<string,mixed>
     */
    public static function createUpdateViewModel(array $releases, string $installedVersion, Language $language): array
    {
        $viewModel = $releases;
        $viewModel['installedVersion'] = $installedVersion;
        if ($viewModel['connected']) {
            $stableAvailable = $viewModel['stable'] !== null && version_compare($viewModel['stable']['version'], $installedVersion, '>');
            $betaAvailable = $viewModel['beta'] !== null && version_compare($viewModel['beta']['version'], $installedVersion, '>');
            $viewModel['status'] = $stableAvailable || $betaAvailable
                ? $language->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_AVAILABLE')
                : $language->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_CURRENT');
            $viewModel['statusClass'] = $stableAvailable || $betaAvailable ? 'info' : 'success';
        }

        return $viewModel;
    }

    /**
     * Render the asynchronous update-check result with its Smarty partial.

     * @param array<string,mixed> $releaseCheck
     */
    public static function renderUpdateCheck(PagePresenter $page, array $releaseCheck): string
    {
        $page->assignSmartyVariable('releaseCheck', $releaseCheck);

        return $page->getSmartyTemplate()->fetch('plugin.event-attendance.settings.update-status.tpl');
    }
}
