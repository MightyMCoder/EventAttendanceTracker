<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 */


namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;

/** Stores the organization-specific legacy plugin configuration. */
final class LegacySettingsService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<string,mixed> $defaults @return array<string,mixed> */
    public function get(int $organizationId, array $defaults): array
    {
        if (!$this->database->tableExists(TABLE_PREFIX . '_event_attendance_settings')) {
            return $defaults;
        }

        $statement = $this->database->queryPrepared(
            'SELECT eas_name, eas_value
               FROM ' . TABLE_PREFIX . '_event_attendance_settings
              WHERE eas_org_id = ?',
            array($organizationId)
        );
        foreach ($statement->fetchAll() as $row) {
            $value = json_decode((string) $row['eas_value'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $defaults[(string) $row['eas_name']] = $value;
            }
        }

        return $defaults;
    }

    /** @param array<string,mixed> $settings */
    public function save(int $organizationId, array $settings): void
    {
        $this->database->queryPrepared(
            'DELETE FROM ' . TABLE_PREFIX . '_event_attendance_settings WHERE eas_org_id = ?',
            array($organizationId)
        );
        foreach ($settings as $name => $value) {
            $this->database->queryPrepared(
                'INSERT INTO ' . TABLE_PREFIX . '_event_attendance_settings (eas_org_id, eas_name, eas_value) VALUES (?, ?, ?)',
                array($organizationId, $name, json_encode($value, JSON_THROW_ON_ERROR))
            );
        }
    }
}
