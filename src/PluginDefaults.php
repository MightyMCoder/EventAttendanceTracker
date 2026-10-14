<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance;

/** Provides the initial values for per-organization plugin settings. */
final class PluginDefaults
{
    /** @return array<string, bool|int|string|array<int>> */
    public static function settings(): array
    {
        return array(
            // The complete release identifier is persisted so a beta increment
            // also runs the normal, idempotent plugin update workflow.
            'event_attendance_schema_version' => EventAttendance::getVersionLabel(),
            'event_attendance_tracker_manager_roles' => array(),
            'event_attendance_members_may_excuse_themselves' => false,
            'event_attendance_self_excuse_roles' => array(),
            'event_attendance_self_excuse_deadline_hours' => 0,
            'event_attendance_rate_counts_excused' => false,
            'event_attendance_rate_decimal_places' => 0,
            'event_attendance_rate_red_threshold' => 50,
            'event_attendance_rate_orange_threshold' => 70,
            'event_attendance_recording_lock_days' => 0,
            'event_attendance_pdf_logo_file' => '',
            'event_attendance_export_filename' => 'PLG_EVENT_ATTENDANCE_TRACKER_NAME',
            'event_attendance_automation_method' => 'disabled',
            'event_attendance_webcron_token' => ''
        );
    }

    private function __construct()
    {
    }
}
