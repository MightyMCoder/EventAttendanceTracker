<?php

namespace AdmidioPlugin\EventAttendance;

/**
 * Shared plugin identifiers.
 *
 * Admidio 5 has no plugin registry, hooks or preferences panels. The plugin
 * therefore stores its own per-organization settings and creates its menu entry.
 */
final class EventAttendance
{
    public const PLUGIN_ID = 'event-attendance';
    public const PLUGIN_NAME_TRANSLATION_ID = 'PLG_EVENT_ATTENDANCE_TRACKER_NAME';
    public const ENTRY_POINT = 'event-attendance-tracker.php';
    public const VERSION = '5.0.4';

    private function __construct()
    {
    }

}
