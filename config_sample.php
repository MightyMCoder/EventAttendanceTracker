<?php

declare(strict_types=1);

/**
 * Event Attendance Tracker configuration for Admidio 5.
 *
 * Copy this file to config.php and return only the values that differ from the
 * defaults. The file is deliberately local and must not be committed.
 */
return array(
    // Role IDs allowed to create and manage trackers. Administrators always have access.
    'event_attendance_tracker_manager_roles' => array(),
    'event_attendance_members_may_excuse_themselves' => false,
    'event_attendance_self_excuse_deadline_hours' => 0,
    'event_attendance_rate_counts_excused' => false,
    'event_attendance_rate_decimal_places' => 0,
    'event_attendance_rate_red_threshold' => 50,
    'event_attendance_rate_orange_threshold' => 70,
    'event_attendance_recording_lock_days' => 0,
    'event_attendance_pdf_logo_file' => '',
    'event_attendance_export_filename' => 'PLG_EVENT_ATTENDANCE_NAME',
    // Configure webcron and the secret token together, or leave automation disabled.
    'event_attendance_automation_method' => 'disabled',
    'event_attendance_webcron_token' => '',
);
