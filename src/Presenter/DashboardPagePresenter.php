<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 */


namespace AdmidioPlugin\EventAttendance\Presenter;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class DashboardPagePresenter extends PluginPagePresenter
{
    public static function create(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_dashboard', $headline, $plugin);
        $page->addDashboardNavigation($plugin);

        return $page;
    }
}
