<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


/**
 * Minimal PSR-4 loader for Admidio 5 plugins.
 *
 * Admidio 5 does not discover a plugin's composer/autoload definition. Keep this
 * loader local to the plugin so the core installation stays untouched.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'AdmidioPlugin\\EventAttendance\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Admidio 5 discovers language folders only below its configured plugin path.
// Register this folder explicitly so translations also work when the plugin is
// deployed from another directory (for example during development or updates).
if (isset($GLOBALS['gL10n'])) {
    $GLOBALS['gL10n']->addLanguageFolderPath(__DIR__ . '/languages');
}
