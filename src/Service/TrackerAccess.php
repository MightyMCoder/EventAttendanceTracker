<?php
namespace AdmidioPlugin\EventAttendance\Service;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use AdmidioPlugin\EventAttendance\EventAttendance;
final class TrackerAccess { public static function mayManage(): bool { global $gCurrentUser; if ($gCurrentUser->isAdministrator()) { return true; } $plugin = PluginRegistry::get(EventAttendance::PLUGIN_ID); $roles = $plugin?->getSettingValues()['event_attendance_tracker_manager_roles'] ?? array(); return count(array_intersect(array_map('intval', $roles), $gCurrentUser->getRoleMemberships())) > 0; } }
