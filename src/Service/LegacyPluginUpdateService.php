<?php
/**
 * Contains the XML update-step runner for the Admidio 5 plugin lifecycle.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use SimpleXMLElement;

/** Executes versioned database update steps from the plugin's XML update files. */
final class LegacyPluginUpdateService
{
    public function __construct(
        private readonly Database $database,
        private readonly LegacySettingsService $settingsService,
        private readonly string $directory
    ) {
    }

    /**
     * Execute all update files up to the target major/minor version.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function apply(int $organizationId, array $settings, string $targetVersion): array
    {
        foreach ($this->getUpdateFiles($targetVersion) as $version => $file) {
            $stepSetting = 'event_attendance_update_' . str_replace('.', '_', $version) . '_step';
            $completedStep = max(0, (int) ($settings[$stepSetting] ?? 0));
            $xml = $this->loadXml($file);

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
                    $this->settingsService->save($organizationId, $settings);
                    continue;
                }

                $sql = trim((string) $step);
                if ($sql === '') {
                    throw new \RuntimeException('Plugin update steps must contain an SQL statement.');
                }
                $ignoreErrors = isset($step['error']) && (string) $step['error'] === 'ignore';
                $this->database->queryPrepared(
                    Database::prepareSqlAdmidioParameters($sql),
                    array(),
                    !$ignoreErrors
                );
                $settings[$stepSetting] = $stepId;
                $this->settingsService->save($organizationId, $settings);
            }
        }

        $this->database->initializeTableColumnProperties();

        return $settings;
    }

    /** @return array<string,string> */
    private function getUpdateFiles(string $targetVersion): array
    {
        $target = $this->normalizeVersion($targetVersion);
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

    private function loadXml(string $file): SimpleXMLElement
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

    private function normalizeVersion(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $matches) !== 1) {
            throw new \RuntimeException('Plugin version must start with a major and minor version number.');
        }

        return $matches[1] . '.' . $matches[2];
    }
}
