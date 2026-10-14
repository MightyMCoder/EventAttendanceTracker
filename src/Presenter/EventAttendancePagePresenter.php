<?php
/**
 * Contains page factories for all Event Attendance Tracker views.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

/** Creates consistently configured pages for the plugin's different views. */
final class EventAttendancePagePresenter extends PluginPagePresenter
{
    public static function createManagement(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createPage('adm_event_attendance_manage', $headline, $plugin);
    }

    public static function createDashboard(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_dashboard', $headline, $plugin);
        $page->addDashboardNavigation($plugin);

        return $page;
    }

    public static function createSettings(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_settings', $headline, $plugin);
    }

    public static function createSelfExcuse(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_self_excuse', $headline, $plugin);
    }

    public static function createAttendance(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_record', $headline, $plugin, false);
    }

    public static function createTrackerEdit(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_tracker_edit', $headline, $plugin);
        $page->addDashboardNavigation($plugin);
        $page->addConfigurationNavigation($plugin);
        $page->addCurrentNavigation();

        return $page;
    }

    public static function createTrackerConfiguration(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_configuration', $headline, $plugin);
    }

    public static function createEvents(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_events', $headline, $plugin, false);
    }

    public static function createReport(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_report', $headline, $plugin, false);
    }

    public static function createReports(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createDashboardChildPage('adm_event_attendance_reports', $headline, $plugin);
    }

    public static function createPrintReport(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_report_print', $headline, $plugin);
        $page->setPrintMode();

        return $page;
    }

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
}
