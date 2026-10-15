<?php
/**
 * Transfers the settings of the Admidio 5 Event Attendance Tracker to native
 * Admidio plugin preferences.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\Organizations\Entity\Organization;

/**
 * Migrates the one-time, per-organization configuration from the legacy table.
 */
final class LegacySettingsMigrationService
{
    private const LEGACY_SETTINGS_TABLE = TABLE_PREFIX . '_event_attendance_settings';
    private const MIGRATION_SETTING = 'event_attendance_legacy_migration_completed';

    /**
     * The native settings that were already available in the Admidio 5 release.
     *
     * @var array<int,string>
     */
    private const SETTING_NAMES = array(
        'event_attendance_members_may_excuse_themselves',
        'event_attendance_self_excuse_roles',
        'event_attendance_tracker_manager_roles',
        'event_attendance_sync_core_cancellation',
        'event_attendance_automation_method',
        'event_attendance_webcron_token',
        'event_attendance_self_excuse_deadline_hours',
        'event_attendance_rate_counts_excused',
        'event_attendance_rate_decimal_places',
        'event_attendance_rate_red_threshold',
        'event_attendance_rate_orange_threshold',
        'event_attendance_recording_lock_days',
        'event_attendance_pdf_logo_file',
        'event_attendance_export_filename'
    );

    /**
     * The class only offers static migration helpers and must not be instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Copy legacy settings for every organization exactly once.
     *
     * The legacy table is retained deliberately. It provides a recoverable source until a future
     * major version can remove the bridge, while the marker prevents it from overwriting settings
     * that an administrator changes after the migration.
     *
     * @return void
     */
    public static function migrate(): void
    {
        global $gDb, $gSettingsManager;

        if (!$gDb instanceof Database || !$gDb->tableExists(self::LEGACY_SETTINGS_TABLE)) {
            return;
        }

        $legacySettings = self::loadLegacySettings($gDb);
        $organizationStatement = $gDb->queryPrepared('SELECT org_id FROM ' . TBL_ORGANIZATIONS);

        while ($organizationId = (int) $organizationStatement->fetchColumn()) {
            $organization = new Organization($gDb, $organizationId);
            $settingsManager =& $organization->getSettingsManager();

            if ($settingsManager->has(self::MIGRATION_SETTING)
                && $settingsManager->getBool(self::MIGRATION_SETTING)) {
                continue;
            }

            foreach ($legacySettings[$organizationId] ?? array() as $name => $value) {
                $settingsManager->set($name, self::normalizeValue($name, $value));
            }

            $settingsManager->set(self::MIGRATION_SETTING, true);
        }

        if (isset($gSettingsManager)) {
            $gSettingsManager->resetAll();
        }
    }

    /**
     * Load the known legacy JSON values grouped by organization.
     *
     * @param Database $database The Admidio database connection.
     * @return array<int,array<string,mixed>>
     */
    private static function loadLegacySettings(Database $database): array
    {
        $statement = $database->queryPrepared(
            'SELECT eas_org_id, eas_name, eas_value
               FROM ' . self::LEGACY_SETTINGS_TABLE . '
              WHERE eas_name IN (' . implode(',', array_fill(0, count(self::SETTING_NAMES), '?')) . ')',
            self::SETTING_NAMES
        );
        $settings = array();
        while ($row = $statement->fetch()) {
            try {
                $value = json_decode((string) $row['eas_value'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $settings[(int) $row['eas_org_id']][(string) $row['eas_name']] = $value;
        }

        return $settings;
    }

    /**
     * Convert legacy JSON values to the scalar or array types accepted by SettingsManager.
     *
     * @param string $name The native preference name.
     * @param mixed  $value The decoded legacy value.
     * @return bool|int|string|array<int,int>
     */
    private static function normalizeValue(string $name, mixed $value): bool|int|string|array
    {
        if (in_array($name, array('event_attendance_tracker_manager_roles', 'event_attendance_self_excuse_roles'), true)) {
            return array_values(array_unique(array_filter(array_map('intval', is_array($value) ? $value : array()), static fn (int $roleId): bool => $roleId > 0)));
        }

        if (in_array($name, array('event_attendance_members_may_excuse_themselves', 'event_attendance_sync_core_cancellation', 'event_attendance_rate_counts_excused'), true)) {
            return (bool) $value;
        }

        if (in_array($name, array('event_attendance_self_excuse_deadline_hours', 'event_attendance_rate_decimal_places', 'event_attendance_rate_red_threshold', 'event_attendance_rate_orange_threshold', 'event_attendance_recording_lock_days'), true)) {
            return max(0, (int) $value);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
