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
    /** @param array<string,mixed> $settings */
    public static function create(PagePresenter $page, LegacyPluginContext $plugin, array $settings): void
    {
        global $gCurrentOrgId, $gDb, $gL10n;
        $make = static function (string $panel, string $template) use ($page, $plugin): FormPresenter {
            return new FormPresenter('adm_event_attendance_settings_' . $panel, $template, SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'save_settings', 'panel' => $panel)), $page, array('class' => 'form-preferences'));
        };
        $page->addHtml('<div class="accordion" id="adm_event_attendance_settings_accordion">');
        $form = $make('access', 'plugin.event-attendance.settings.access.tpl');
        $form->addSelectBoxFromSql('event_attendance_tracker_manager_roles', $gL10n->get('SYS_EVENT_ATTENDANCE_TRACKER_MANAGER_ROLES'), $gDb, array('query' => 'SELECT rol_id, rol_name FROM ' . TBL_ROLES . ' INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id WHERE cat_org_id = ? AND rol_valid = true AND rol_system = false AND rol_administrator = false AND cat_name_intern <> \'EVENTS\' ORDER BY cat_sequence, rol_name', 'params' => array($gCurrentOrgId)), array('defaultValue' => $settings['event_attendance_tracker_manager_roles'], 'multiselect' => true, 'showContextDependentFirstEntry' => false, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_TRACKER_MANAGER_ROLES_DESC'));
        self::finish($form);
        $form = $make('automation', 'plugin.event-attendance.settings.automation.tpl');
        $form->addSelectBox('event_attendance_automation_method', $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD'), array('disabled' => $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_DISABLED'), 'cli' => $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_CLI'), 'webcron' => $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_WEBCRON')), array('defaultValue' => $settings['event_attendance_automation_method'], 'showContextDependentFirstEntry' => false, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_DESC'));
        $webcronToken = (string) $settings['event_attendance_webcron_token'];
        if ($webcronToken === '') { $webcronToken = bin2hex(random_bytes(32)); }
        $form->addInput('event_attendance_webcron_token', 'Web-Cron-Token', $webcronToken, array('maxLength' => 128));
        $organization = (string) $GLOBALS['gCurrentOrganization']->getValue('org_shortname');
        $cliCommand = '/usr/bin/php ' . ADMIDIO_PATH . FOLDER_PLUGINS . '/event-attendance/cli.php --organization=' . $organization;
        $form->addCustomContent('event_attendance_automation_cli', $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_CLI'), '<p class="form-text">' . htmlspecialchars($gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_CLI_HELP'), ENT_QUOTES, 'UTF-8') . '</p><code>' . htmlspecialchars('*/15 * * * * ' . $cliCommand, ENT_QUOTES, 'UTF-8') . '</code>');
        $form->addCustomContent('event_attendance_automation_webcron', $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_METHOD_WEBCRON'), '<p class="form-text">' . htmlspecialchars($gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_WEBCRON_HELP'), ENT_QUOTES, 'UTF-8') . '</p><code>' . htmlspecialchars($plugin->getUrl('webcron.php') . '?organization=' . rawurlencode($organization) . '&token=' . rawurlencode($webcronToken), ENT_QUOTES, 'UTF-8') . '</code>');
        $form->addCustomContent(
            'event_attendance_automation_status',
            $gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS'),
            self::buildAutomationStatus((new AttendanceAutomationService($gDb))->getLastRun((int) $gCurrentOrgId))
        );
        self::finish($form);
        $form = $make('rules', 'plugin.event-attendance.settings.rules.tpl');
        $form->addCheckbox('event_attendance_members_may_excuse_themselves', $gL10n->get('SYS_EVENT_ATTENDANCE_EXCUSE_SELF'), (bool) $settings['event_attendance_members_may_excuse_themselves']);
        $form->addInput('event_attendance_self_excuse_deadline_hours', $gL10n->get('SYS_EVENT_ATTENDANCE_SELF_EXCUSE_DEADLINE'), (string) $settings['event_attendance_self_excuse_deadline_hours'], array('type' => 'number', 'minNumber' => 0, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_SELF_EXCUSE_DEADLINE_DESC'));
        $form->addCheckbox('event_attendance_rate_counts_excused', $gL10n->get('SYS_EVENT_ATTENDANCE_RATE_COUNTS_EXCUSED'), (bool) $settings['event_attendance_rate_counts_excused']);
        foreach (array('event_attendance_rate_decimal_places' => array('SYS_EVENT_ATTENDANCE_RATE_DECIMAL_PLACES', 4), 'event_attendance_rate_red_threshold' => array('SYS_EVENT_ATTENDANCE_RATE_RED_THRESHOLD', 100), 'event_attendance_rate_orange_threshold' => array('SYS_EVENT_ATTENDANCE_RATE_ORANGE_THRESHOLD', 100), 'event_attendance_recording_lock_days' => array('SYS_EVENT_ATTENDANCE_RECORDING_LOCK_DAYS', null)) as $field => $meta) { $opt = array('type' => 'number', 'minNumber' => 0, 'helpTextId' => $meta[0] . '_DESC'); if ($meta[1] !== null) { $opt['maxNumber'] = $meta[1]; } $form->addInput($field, $gL10n->get($meta[0]), (string) $settings[$field], $opt); } self::finish($form);
        $form = $make('export', 'plugin.event-attendance.settings.export.tpl');
        $form->addInput('event_attendance_pdf_logo_file', $gL10n->get('SYS_EVENT_ATTENDANCE_PDF_LOGO_FILE'), (string) $settings['event_attendance_pdf_logo_file'], array('maxLength' => 255, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_PDF_LOGO_FILE_DESC'));
        $form->addInput('event_attendance_export_filename', $gL10n->get('SYS_EVENT_ATTENDANCE_EXPORT_FILENAME'), (string) $settings['event_attendance_export_filename'], array('maxLength' => 100, 'helpTextId' => 'SYS_EVENT_ATTENDANCE_EXPORT_FILENAME_DESC')); self::finish($form);
    }

    private static function buildAutomationStatus(?string $lastRun): string
    {
        global $gL10n, $gSettingsManager;

        $statusId = 'SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS_NEVER';
        $badgeClass = 'text-bg-danger';
        $lastRunText = $gL10n->get($statusId);

        if ($lastRun !== null) {
            try {
                $lastRunAt = new DateTimeImmutable($lastRun);
                $ageInSeconds = max(0, time() - $lastRunAt->getTimestamp());
                $statusId = $ageInSeconds <= 15 * 60
                    ? 'SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS_SUCCESS'
                    : ($ageInSeconds <= 60 * 60
                        ? 'SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS_WARNING'
                        : 'SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS_DANGER');
                $badgeClass = $statusId === 'SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS_SUCCESS'
                    ? 'text-bg-success'
                    : ($statusId === 'SYS_EVENT_ATTENDANCE_AUTOMATION_STATUS_WARNING' ? 'text-bg-warning' : 'text-bg-danger');
                $lastRunText = $lastRunAt->format(
                    $gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time')
                );
            } catch (\Exception) {
                // A malformed legacy value is shown as an unavailable run instead of breaking settings.
            }
        }

        return '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($gL10n->get($statusId), ENT_QUOTES, 'UTF-8')
            . '</span><div class="form-text mt-2">'
            . htmlspecialchars($gL10n->get('SYS_EVENT_ATTENDANCE_AUTOMATION_LAST_RUN', array($lastRunText)), ENT_QUOTES, 'UTF-8')
            . '</div>';
    }

    private static function finish(FormPresenter $form): void { global $gCurrentSession, $gL10n; $form->addSubmitButton('adm_button_save_event_attendance', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')); $form->addToHtmlPage(false); $gCurrentSession->addFormObject($form); }
}
