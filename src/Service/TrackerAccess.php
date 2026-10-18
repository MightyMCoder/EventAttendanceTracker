<?php
/**
 * Contains access checks for the Event Attendance Tracker plugin.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Plugins\PluginRegistry;
use AdmidioPlugin\EventAttendance\EventAttendance;

final class TrackerAccess
{
    public static function mayManage(): bool
    {
        global $gCurrentUser;

        if ($gCurrentUser->isAdministrator()) {
            return true;
        }

        $plugin = PluginRegistry::get(EventAttendance::PLUGIN_ID);
        $roles = $plugin?->getSettingValues()['event_attendance_tracker_manager_roles'] ?? array();

        return count(array_intersect(array_map('intval', $roles), $gCurrentUser->getRoleMemberships())) > 0;
    }

    /**
     * Determine whether the current user may record attendance.
     *
     * Tracker managers retain this permission, while members of the dedicated recorder roles
     * may access only attendance-recording workflows.
     */
    public static function mayRecordAttendance(): bool
    {
        global $gCurrentUser;

        if (self::mayManage()) {
            return true;
        }

        $plugin = PluginRegistry::get(EventAttendance::PLUGIN_ID);
        $roles = $plugin?->getSettingValues()['event_attendance_attendance_recorder_roles'] ?? array();

        return count(array_intersect(array_map('intval', $roles), $gCurrentUser->getRoleMemberships())) > 0;
    }

    public static function mayExcuseSelf(): bool
    {
        global $gCurrentUser;

        if ($gCurrentUser->isAdministrator()) {
            return true;
        }

        $plugin = PluginRegistry::get(EventAttendance::PLUGIN_ID);
        $settings = $plugin?->getSettingValues() ?? array();
        if (!(bool) ($settings['event_attendance_members_may_excuse_themselves'] ?? false)) {
            return false;
        }

        $roles = $settings['event_attendance_self_excuse_roles'] ?? array();

        return count(array_intersect(array_map('intval', $roles), $gCurrentUser->getRoleMemberships())) > 0;
    }
}
