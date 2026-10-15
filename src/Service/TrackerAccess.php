<?php

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
