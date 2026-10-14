<?php
/**
 * Contains the page presenter for printable attendance reports.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance\Presenter;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class PrintReportPagePresenter extends PluginPagePresenter
{
    public static function create(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_report_print', $headline, $plugin);
        $page->setPrintMode();

        return $page;
    }
}
