<?php
/**
 * Contains the legacy Admidio 5 plugin context for settings and plugin URLs.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\EventAttendance\Service\PluginService;

/**
 * Provides settings and URLs for the legacy Admidio 5 plugin layout.
 *
 * Admidio 5 does not provide the plugin context available in newer releases.
 * This small context centralizes the state each plugin page needs instead of
 * passing its directory and settings through every presenter call.
 */
final class LegacyPluginContext
{
    private static ?self $instance = null;

    /**
     * Store the resolved plugin directory and organization settings.
     *
     * @param string              $directory Absolute plugin directory.
     * @param array<string,mixed> $settings  Settings resolved for one organization.
     */
    private function __construct(private readonly string $directory, private readonly array $settings)
    {
    }

    /**
     * Initialize the context and load the organization-specific plugin settings.
     *
     * A request only requires one context. Reusing it prevents settings from being
     * read repeatedly and guarantees that every view uses the same configuration.
     *
     * @return self
     */
    public static function initialize(string $directory, Database $database, int $organizationId): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $settings = (new PluginService($database, $directory))->getSettings($organizationId, PluginDefaults::settings());

        return self::$instance = new self($directory, $settings);
    }

    /**
     * Return the initialized plugin context.
     *
     * Bootstrap must initialize the context before a plugin class asks for it;
     * otherwise the request is structurally invalid and cannot be served safely.
     *
     * @return self
     * @throws \LogicException
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('The event-attendance legacy context was not initialized.');
        }

        return self::$instance;
    }

    /**
     * Return the settings loaded for the active organization.
     *
     * @return array<string,mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * Build the public URL for a plugin file.
     *
     * Legacy callers may still name index.php. Mapping it to the descriptive
     * entry point keeps generated URLs stable without exposing a generic route.
     *
     * @return string
     */
    public function getUrl(string $file): string
    {
        // The plugin uses a descriptive, stable public entry point instead of a generic index.php.
        if ($file === 'index.php') {
            $file = EventAttendance::ENTRY_POINT;
        }

        return ADMIDIO_URL . FOLDER_PLUGINS . '/' . basename($this->directory) . '/' . ltrim($file, '/');
    }

    /**
     * Return the absolute path of the plugin template directory.
     *
     * @return string
     */
    public function templatesDirectory(): string
    {
        return $this->directory . '/templates';
    }
}
