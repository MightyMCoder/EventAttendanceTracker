<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;

/** Installs and removes the legacy Admidio 5 plugin schema. */
final class LegacyPluginManager
{
    public function __construct(private readonly Database $database, private readonly string $directory)
    {
    }

    public function isInstalled(): bool
    {
        return $this->database->tableExists(TABLE_PREFIX . '_event_attendance_trackers');
    }

    public function install(int $organizationId, array $defaults): void
    {
        if ($this->isInstalled()) {
            return;
        }

        $this->runScript('install.sql');
        (new LegacySettingsService($this->database))->save($organizationId, $defaults);
    }

    public function uninstall(): void
    {
        if (!$this->isInstalled()) {
            return;
        }

        $this->runScript('uninstall.sql');
    }

    /** @return int Number of available migrations; v5 migrations are manual SQL files. */
    public function update(int $organizationId, array $defaults): int
    {
        if (!$this->database->tableExists(TABLE_PREFIX . '_event_attendance_settings')) {
            $this->runScript('update_v5_0.sql');
        }
        $settings = (new LegacySettingsService($this->database))->get($organizationId, $defaults);
        $settings['event_attendance_schema_version'] = (string) $defaults['event_attendance_schema_version'];
        (new LegacySettingsService($this->database))->save($organizationId, $settings);
        return 1;
    }

    /** @param array<string,mixed> $defaults */
    public function needsUpdate(int $organizationId, array $defaults): bool
    {
        if (!$this->database->tableExists(TABLE_PREFIX . '_event_attendance_settings')) {
            return true;
        }

        $statement = $this->database->queryPrepared(
            'SELECT eas_value FROM ' . TABLE_PREFIX . '_event_attendance_settings WHERE eas_org_id = ? AND eas_name = ?',
            array($organizationId, 'event_attendance_schema_version')
        );
        $value = $statement->fetchColumn();
        $installedVersion = is_string($value) ? json_decode($value, true) : null;

        return !is_string($installedVersion)
            || version_compare($installedVersion, (string) $defaults['event_attendance_schema_version'], '<');
    }

    private function runScript(string $name): void
    {
        $sql = file_get_contents($this->directory . '/db_scripts/' . $name);
        if ($sql === false) {
            throw new \RuntimeException('Plugin database script could not be read.');
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->database->queryPrepared(Database::prepareSqlAdmidioParameters($statement));
        }
        $this->database->initializeTableColumnProperties();
    }
}
