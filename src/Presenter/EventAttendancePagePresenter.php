<?php
/**
 * Contains page factories for all Event Attendance Tracker views.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;
use JsonException;

/**
 * Creates consistently configured pages for the plugin's different views.
 */
final class EventAttendancePagePresenter extends PagePresenter
{
    /**
     * Create the installation or update management page.
     */
    public static function createManagement(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createPage('adm_event_attendance_manage', $headline, $plugin);
    }

    /**
     * Create the main tracker overview page.
     */
    public static function createDashboard(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_dashboard', $headline, $plugin);
        $page->addDashboardNavigation($plugin);

        return $page;
    }

    /**
     * Create the plugin settings page.
     */
    public static function createSettings(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_settings', $headline, $plugin);
    }

    /**
     * Create the member self-excuse page.
     */
    public static function createSelfExcuse(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_self_excuse', $headline, $plugin);
    }

    /**
     * Create the attendance recording page.
     */
    public static function createAttendance(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_record', $headline, $plugin, false);
    }

    /**
     * Create the tracker configuration edit page.
     */
    public static function createTrackerEdit(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_tracker_edit', $headline, $plugin);
        $page->addDashboardNavigation($plugin);
        $page->addConfigurationNavigation($plugin);
        $page->addCurrentNavigation();

        return $page;
    }

    /**
     * Create the tracker configuration list page.
     */
    public static function createTrackerConfiguration(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_configuration', $headline, $plugin);
    }

    /**
     * Create the occurrences page for a tracker.
     */
    public static function createEvents(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_events', $headline, $plugin, false);
    }

    /**
     * Create the interactive attendance report page.
     */
    public static function createReport(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_report', $headline, $plugin, false);
    }

    /**
     * Create the report overview page.
     */
    public static function createReports(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_reports', $headline, $plugin);
    }

    /**
     * Create the print-friendly attendance report page.
     */
    public static function createPrintReport(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_report_print', $headline, $plugin);
        $page->setPrintMode();

        return $page;
    }

    /**
     * Create the Smarty page used to render a PDF report.
     */
    public static function createPdfReport(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createPage('adm_event_attendance_report_pdf', $headline, $plugin);
    }

    private static function createDashboardChildPage(
        string $htmlId,
        string $headline,
        LegacyPluginContext $plugin,
        bool $addCurrentNavigation = true
    ): self {
        $page = self::createPage($htmlId, $headline, $plugin);
        $page->addDashboardNavigation($plugin);
        if ($addCurrentNavigation) {
            $page->addCurrentNavigation();
        }

        return $page;
    }

    /**
     * Add the mandatory plugin-update form to a page.
     */
    public static function createUpdateForm(PagePresenter $page, LegacyPluginContext $plugin): void
    {
        global $gCurrentSession, $gL10n;

        $form = new FormPresenter(
            'adm_event_attendance_update_form',
            'plugin.event-attendance.update-required.tpl',
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'update')),
            $page,
            array('setFocus' => false, 'showRequiredFields' => false)
        );
        $form->addSubmitButton(
            'adm_button_update_event_attendance',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERFORM_UPDATE'),
            array('icon' => 'bi-arrow-repeat', 'class' => 'offset-sm-3')
        );
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * Send an Ajax response encoded as JSON.

     * @param array<string,mixed> $payload
     */
    public static function sendJson(array $payload): void
    {
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Send a standard successful Ajax response.

     * @throws JsonException
     */
    public static function sendJsonSuccess(?string $url = null, ?string $message = null): void
    {
        $payload = array('status' => 'success');
        if ($url !== null) {
            $payload['url'] = $url;
        }
        if ($message !== null) {
            $payload['message'] = $message;
        }

        self::sendJson($payload);
    }

    /**
     * Initialize a page with the shared template directory and headline.
     */
    private static function createPage(string $htmlId, string $headline, LegacyPluginContext $plugin): self
    {
        $page = new self();
        $page->setHtmlID($htmlId);
        $page->setHeadline($headline);
        $page->addTemplateFolder($plugin->templatesDirectory());

        return $page;
    }

    /**
     * Add the plugin dashboard entry to the navigation.
     */
    private function addDashboardNavigation(LegacyPluginContext $plugin): void
    {
        global $gL10n, $gNavigation;

        $gNavigation->addStartUrl(
            $plugin->getUrl('index.php'),
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE'),
            'bi-person-check-fill'
        );
    }

    /**
     * Add the tracker configuration navigation entry.
     */
    private function addConfigurationNavigation(LegacyPluginContext $plugin): void
    {
        global $gL10n, $gNavigation;

        $gNavigation->addUrl(
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config')),
            $gL10n->get('SYS_CONFIGURATION')
        );
    }

    /**
     * Add the current page to the navigation breadcrumb.
     */
    public function addCurrentNavigation(): void
    {
        global $gNavigation;

        $gNavigation->addUrl(CURRENT_URL, $this->getHeadline());
    }

    /**
     * Add an arbitrary navigation entry to the breadcrumb.
     */
    public function addNavigation(string $url, string $label): void
    {
        global $gNavigation;

        $gNavigation->addUrl($url, $label);
    }
}
