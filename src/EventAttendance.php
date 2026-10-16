<?php
/**
 * Contains shared identifiers, version information and constants for the plugin.
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
     * The database-compatible plugin version.
     *
     * Changing this value requires the normal plugin update workflow when it is
     * already installed. The first published release train begins at version 1.
     */
    public const VERSION = '1.0.0';

    /**
     * Increment this counter for every beta build. The complete version is stored
     * with the plugin settings, so an increment opens the normal update workflow.
     * Set it to null for a production release.
     */
    public const BETA_VERSION = 3;

    /**
     * Prevent instantiation of the plugin metadata holder.
     *
     * The plugin identifier and release information are constants shared by the
     * bootstrap, lifecycle workflow and presenters.
     */
    private function __construct()
    {
    }

    /**
     * Return the current release label including an optional beta suffix.
     *
     * The returned value is persisted as the installed schema version. Therefore
     * beta builds are distinguishable from their later stable release.
     *
     * @return string
     */
    public static function getVersionLabel(): string
    {
        return self::BETA_VERSION === null
            ? self::VERSION
            : self::VERSION . '-beta.' . self::BETA_VERSION;
    }

}
