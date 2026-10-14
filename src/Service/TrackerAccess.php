<?php

namespace AdmidioPlugin\EventAttendance\Service;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class TrackerAccess
{
    public static function mayManage(): bool
    {
        global $gCurrentUser;

        if ($gCurrentUser->isAdministrator()) {
            return true;
        }

        $roles = LegacyPluginContext::instance()->settings()['event_attendance_tracker_manager_roles'] ?? array();

        return count(array_intersect(array_map('intval', $roles), $gCurrentUser->getRoleMemberships())) > 0;
    }

    public static function mayExcuseSelf(): bool
    {
        global $gCurrentUser;

        $settings = LegacyPluginContext::instance()->settings();
        if (!(bool) ($settings['event_attendance_members_may_excuse_themselves'] ?? false)) {
            return false;
        }

        if ($gCurrentUser->isAdministrator()) {
            return true;
        }

        return count(array_intersect(
            array_map('intval', $settings['event_attendance_self_excuse_roles'] ?? array()),
            $gCurrentUser->getRoleMemberships()
        )) > 0;
    }
}
