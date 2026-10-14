<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

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
    /**
     * The database-compatible development version.
     *
     * Keep this in the 0.0.x range until the first production release. Changing
     * it requires the normal plugin update workflow when it is already installed.
     */
    public const VERSION = '0.0.5';

    /**
     * Increment this counter for every beta build. The complete version is stored
     * with the plugin settings, so an increment opens the normal update workflow.
     * Set it to null for a production release.
     */
    public const BETA_VERSION = 5;

    private function __construct()
    {
    }

    public static function getVersionLabel(): string
    {
        return self::BETA_VERSION === null
            ? self::VERSION
            : self::VERSION . '-beta.' . self::BETA_VERSION;
    }

}
