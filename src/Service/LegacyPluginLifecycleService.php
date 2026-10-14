<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\Menu\Entity\MenuEntry;
use Admidio\Roles\Entity\RolesRights;
use AdmidioPlugin\EventAttendance\EventAttendance;

/** Installs and removes the legacy Admidio 5 plugin schema. */
final class LegacyPluginLifecycleService
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
        $this->ensureMenuEntry($organizationId, $defaults);
    }

    public function uninstall(): void
    {
        if (!$this->isInstalled()) {
            return;
        }

        $this->removeMenuEntry();
        $this->runScript('uninstall.sql');
    }

    /** @return int Number of available migrations; v5 migrations are manual SQL files. */
    public function update(int $organizationId, array $defaults): int
    {
        if (!$this->database->tableExists(TABLE_PREFIX . '_event_attendance_settings')) {
            $this->runScript('update_v5_0.sql');
        }
        $trackerColumns = $this->database->getTableColumns(TABLE_PREFIX . '_event_attendance_trackers');
        if (!in_array('eat_cat_id', $trackerColumns, true) || !in_array('eat_source_start', $trackerColumns, true)) {
            $this->runScript('update_v5_0_4.sql');
        }
        $settings = (new LegacySettingsService($this->database))->get($organizationId, $defaults);
        $settings['event_attendance_schema_version'] = (string) $defaults['event_attendance_schema_version'];
        (new LegacySettingsService($this->database))->save($organizationId, $settings);
        $this->ensureMenuEntry($organizationId, $settings);
        return 1;
    }

    /** @param array<string, mixed> $settings */
    public function synchronizeMenuEntry(int $organizationId, array $settings): void
    {
        $this->ensureMenuEntry($organizationId, $settings);
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
            || $installedVersion !== (string) $defaults['event_attendance_schema_version'];
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

    /** @param array<string,mixed> $settings */
    private function ensureMenuEntry(int $organizationId, array $settings): void
    {
        $url = FOLDER_PLUGINS . '/' . basename($this->directory) . '/' . EventAttendance::ENTRY_POINT;
        $menuEntry = new MenuEntry($this->database);
        if (!$menuEntry->readDataByColumns(array('men_url' => $url))) {
            $menuEntry->setValue('men_node', false);
            $menuEntry->setValue('men_standard', false);
            $menuEntry->setValue('men_url', $url);
            $menuEntry->setValue('men_icon', 'bi-person-check-fill');
            $menuEntry->setValue('men_name', 'PLG_EVENT_ATTENDANCE_TRACKER_NAME');
            $menuEntry->setValue('men_description', 'PLG_EVENT_ATTENDANCE_TRACKER_DESCRIPTION');
        }
        $menuEntry->setValue('men_url', $url);
        // The third root menu node is the Admidio 5 "Extensions" section.
        $menuEntry->setValue('men_men_id_parent', 3);
        $menuEntry->save();

        $roleIds = $this->database->queryPrepared(
            'SELECT rol_id
               FROM ' . TBL_ROLES . '
         INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
              WHERE cat_org_id = ? AND rol_administrator = true',
            array($organizationId)
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ((array) ($settings['event_attendance_tracker_manager_roles'] ?? array()) as $roleId) {
            $roleIds[] = (int) $roleId;
        }
        if ((bool) ($settings['event_attendance_members_may_excuse_themselves'] ?? false)) {
            foreach ((array) ($settings['event_attendance_self_excuse_roles'] ?? array()) as $roleId) {
                $roleIds[] = (int) $roleId;
            }
        }
        (new RolesRights($this->database, 'menu_view', (int) $menuEntry->getValue('men_id')))
            ->saveRoles(array_values(array_unique(array_map('intval', $roleIds))));
    }

    private function removeMenuEntry(): void
    {
        $url = FOLDER_PLUGINS . '/' . basename($this->directory) . '/' . EventAttendance::ENTRY_POINT;
        $menuEntry = new MenuEntry($this->database);
        if ($menuEntry->readDataByColumns(array('men_url' => $url))) {
            (new RolesRights($this->database, 'menu_view', (int) $menuEntry->getValue('men_id')))->delete();
            $menuEntry->delete();
        }
    }
}
