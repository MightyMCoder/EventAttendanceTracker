<?php
/**
 * Registers command-line tasks for the Event Attendance Tracker plugin.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

use Admidio\Infrastructure\Cli\CliApplication;
use Admidio\Infrastructure\Cli\CliTaskRegistry;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use AdmidioPlugin\EventAttendance\Service\AttendanceAutomationService;

/* This registration file declares metadata only; the callback runs after actor authorization. */
CliTaskRegistry::register(
    'event-attendance:close-due-events',
    'event-attendance',
    static function (array $arguments, array $options): int {
        global $gCurrentOrgId, $gDb;

        $plugin = PluginRegistry::requireEnabled('event-attendance');
        if (($plugin->getSettingValues()['event_attendance_automation_method'] ?? 'disabled') !== 'cli') {
            CliApplication::writeSuccess('The system cron automation is not enabled for this organization.', $options);
            return CliApplication::EXIT_SUCCESS;
        }

        (new AttendanceAutomationService($gDb))->runForOrganization((int) $gCurrentOrgId);
        CliApplication::writeSuccess('Closed due event-attendance records.', $options);

        return CliApplication::EXIT_SUCCESS;
    },
    'Snapshot expected attendees and mark overdue open attendance records absent.',
    'event-attendance:close-due-events'
);
