<?php
/**
 * Contains the installation, update and uninstallation workflow for Admidio 5.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use Admidio\Menu\Entity\MenuEntry;
use Admidio\Roles\Entity\RolesRights;
use AdmidioPlugin\EventAttendance\EventAttendance;
use SimpleXMLElement;

/**
 * Installs and removes the legacy Admidio 5 plugin schema.
 */
final class PluginService
{
    private const RELEASE_API_URL = 'https://api.github.com/repos/MightyMCoder/EventAttendanceTracker/releases';

    /**
     * Create the central plugin lifecycle service.
     */
    public function __construct(private readonly Database $database, private readonly string $directory)
    {
    }

    /**
     * Determine whether the plugin schema is installed.
     */
    public function isInstalled(): bool
    {
        return $this->database->tableExists(TABLE_PREFIX . '_event_attendance_trackers');
    }

    /**
     * Install the plugin schema, settings and protected menu entry.

     * @param array<string,mixed> $defaults
     */
    public function install(int $organizationId, array $defaults): void
    {
        if ($this->isInstalled()) {
            return;
        }

        $this->runScript('install.sql');
        $this->saveSettings($organizationId, $defaults);
        $this->ensureMenuEntry($organizationId, $defaults);
    }

    /**
     * Remove the plugin menu entry and database schema.
     */
    public function uninstall(): void
    {
        if (!$this->isInstalled()) {
            return;
        }

        $this->removeMenuEntry();
        $this->runScript('uninstall.sql');
    }

    /**
     * Execute all outstanding plugin update steps for one organization.
     *
     * The stored schema version includes beta suffixes. A version change must
     * therefore run the same idempotent workflow for beta and stable releases.
     *
     * @return int Number of completed update operations.
     */
    public function update(int $organizationId, array $defaults): int
    {
        $settings = $this->getSettings($organizationId, $defaults);
        $settings = $this->applyUpdateSteps($organizationId, $settings, (string) $defaults['event_attendance_schema_version']);
        $settings['event_attendance_schema_version'] = (string) $defaults['event_attendance_schema_version'];
        $this->saveSettings($organizationId, $settings);
        $this->ensureMenuEntry($organizationId, $settings);
        return 1;
    }

    /**
     * Synchronize the plugin menu entry with the current access settings.
     *
     * @param array<string, mixed> $settings
     * @return void
     */
    public function synchronizeMenuEntry(int $organizationId, array $settings): void
    {
        $this->ensureMenuEntry($organizationId, $settings);
    }

    /**
     * Determine whether the installed organization schema differs from the code.
     *
     * @param array<string,mixed> $defaults
     * @return bool
     */
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

    /**
     * Get organization-specific settings merged with the plugin defaults.

     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    public function getSettings(int $organizationId, array $defaults): array
    {
        if (!$this->database->tableExists(TABLE_PREFIX . '_event_attendance_settings')) {
            return $defaults;
        }

        $statement = $this->database->queryPrepared(
            'SELECT eas_name, eas_value FROM ' . TABLE_PREFIX . '_event_attendance_settings WHERE eas_org_id = ?',
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

    /**
     * Persist all organization-specific plugin settings.

     * @param array<string,mixed> $settings
     */
    public function saveSettings(int $organizationId, array $settings): void
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

    /**
     * Retrieve the newest stable and beta releases from GitHub.
     *
     * @return array{connected:bool,stable:array{version:string,url:string}|null,beta:array{version:string,url:string}|null}
     */
    public function getReleasedVersions(): array
    {
        $response = $this->requestReleaseApi();
        if ($response === null) {
            return array('connected' => false, 'stable' => null, 'beta' => null);
        }

        $releases = json_decode($response, true);
        if (!is_array($releases) || !array_is_list($releases)) {
            return array('connected' => false, 'stable' => null, 'beta' => null);
        }

        $stable = null;
        $beta = null;
        foreach ($releases as $release) {
            if (!is_array($release) || !isset($release['tag_name'], $release['html_url']) || !is_string($release['tag_name']) || !is_string($release['html_url']) || !str_starts_with($release['html_url'], 'https://github.com/MightyMCoder/EventAttendanceTracker/')) {
                continue;
            }

            $version = ltrim($release['tag_name'], 'v');
            if ($version === '') {
                continue;
            }
            $entry = array('version' => $version, 'url' => $release['html_url']);
            if (($release['prerelease'] ?? false) === true && $beta === null) {
                $beta = $entry;
            } elseif (($release['prerelease'] ?? false) !== true && $stable === null) {
                $stable = $entry;
            }
        }

        return array('connected' => true, 'stable' => $stable, 'beta' => $beta);
    }

    /**
     * Execute outstanding XML database update steps.

     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function applyUpdateSteps(int $organizationId, array $settings, string $targetVersion): array
    {
        foreach ($this->getUpdateFiles($targetVersion) as $version => $file) {
            $stepSetting = 'event_attendance_update_' . str_replace('.', '_', $version) . '_step';
            $completedStep = max(0, (int) ($settings[$stepSetting] ?? 0));
            $xml = $this->loadUpdateXml($file);
            foreach ($xml->step as $step) {
                $stepId = (int) ($step['id'] ?? 0);
                if ($stepId <= 0) {
                    throw new \RuntimeException('Plugin update steps require a positive numeric id.');
                }
                if ($stepId <= $completedStep) {
                    continue;
                }
                if (isset($step['database']) && (string) $step['database'] !== DB_ENGINE) {
                    $settings[$stepSetting] = $stepId;
                    $this->saveSettings($organizationId, $settings);
                    continue;
                }

                $sql = trim((string) $step);
                if ($sql === '') {
                    throw new \RuntimeException('Plugin update steps must contain an SQL statement.');
                }
                $this->database->queryPrepared(
                    Database::prepareSqlAdmidioParameters($sql),
                    array(),
                    !(isset($step['error']) && (string) $step['error'] === 'ignore')
                );
                $settings[$stepSetting] = $stepId;
                $this->saveSettings($organizationId, $settings);
            }
        }
        $this->database->initializeTableColumnProperties();

        return $settings;
    }

    /**
     * Return all versioned XML update files up to the requested version.

     * @return array<string,string>
     */
    private function getUpdateFiles(string $targetVersion): array
    {
        $target = $this->normalizeUpdateVersion($targetVersion);
        $updateFiles = array();
        foreach (glob($this->directory . '/db_scripts/update_*.xml') ?: array() as $file) {
            if (preg_match('/update_(\d+)_(\d+)\.xml$/', $file, $matches) !== 1) {
                continue;
            }
            $version = $matches[1] . '.' . $matches[2];
            if (version_compare($version, $target, '<=')) {
                $updateFiles[$version] = $file;
            }
        }
        uksort($updateFiles, 'version_compare');

        return $updateFiles;
    }

    /**
     * Read and validate a plugin XML update file.
     */
    private function loadUpdateXml(string $file): SimpleXMLElement
    {
        $previousErrorHandling = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);
        if (!$xml instanceof SimpleXMLElement || count($errors) > 0 || $xml->getName() !== 'update') {
            throw new \RuntimeException('Plugin update XML file could not be read: ' . basename($file));
        }

        return $xml;
    }

    /**
     * Normalize a version to the major.minor format used by update filenames.
     */
    private function normalizeUpdateVersion(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $matches) !== 1) {
            throw new \RuntimeException('Plugin version must start with a major and minor version number.');
        }

        return $matches[1] . '.' . $matches[2];
    }

    /**
     * Execute an installation or uninstallation SQL script.
     */
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

    /**
     * Create or synchronize the plugin menu entry and its role rights.

     * @param array<string,mixed> $settings
     */
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
        foreach ((array) ($settings['event_attendance_attendance_recorder_roles'] ?? array()) as $roleId) {
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

    /**
     * Remove the plugin menu entry and its role rights.
     */
    private function removeMenuEntry(): void
    {
        $url = FOLDER_PLUGINS . '/' . basename($this->directory) . '/' . EventAttendance::ENTRY_POINT;
        $menuEntry = new MenuEntry($this->database);
        if ($menuEntry->readDataByColumns(array('men_url' => $url))) {
            (new RolesRights($this->database, 'menu_view', (int) $menuEntry->getValue('men_id')))->delete();
            $menuEntry->delete();
        }
    }

    /**
     * Request published releases from GitHub with a short timeout.
     */
    private function requestReleaseApi(): ?string
    {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => "User-Agent: EventAttendanceTracker\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            )
        ));
        $response = @file_get_contents(self::RELEASE_API_URL, false, $context);

        return is_string($response) ? $response : null;
    }
}
