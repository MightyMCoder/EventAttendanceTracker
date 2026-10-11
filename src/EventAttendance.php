<?php

namespace AdmidioPlugin\EventAttendance;

/**
 * Shared plugin identifiers.
 *
 * Admidio 5 has no plugin registry, hooks or preferences panels. Registration
 * is consequently handled by the administrator through config.php and a menu
 * entry, while this class remains the common identifier source.
 */
final class EventAttendance
{
    public const PLUGIN_ID = 'event-attendance';
    public const PLUGIN_NAME_TRANSLATION_ID = 'PLG_EVENT_ATTENDANCE_NAME';

    private function __construct()
    {
    }

}
