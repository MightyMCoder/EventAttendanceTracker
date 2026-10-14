<?php
/**
 * Contains the legacy Admidio 5 plugin context for settings and plugin URLs.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\EventAttendance\Service\PluginService;

/** Provides settings and URLs for the legacy Admidio 5 plugin layout. */
final class LegacyPluginContext
{
    private static ?self $instance = null;

    /** @param array<string,mixed> $settings */
    private function __construct(private readonly string $directory, private readonly array $settings)
    {
    }

    public static function initialize(string $directory, Database $database, int $organizationId): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $settings = (new PluginService($database, $directory))->getSettings($organizationId, PluginDefaults::settings());

        return self::$instance = new self($directory, $settings);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('The event-attendance legacy context was not initialized.');
        }

        return self::$instance;
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return $this->settings;
    }

    public function getUrl(string $file): string
    {
        // The plugin uses a descriptive, stable public entry point instead of a generic index.php.
        if ($file === 'index.php') {
            $file = EventAttendance::ENTRY_POINT;
        }

        return ADMIDIO_URL . FOLDER_PLUGINS . '/' . basename($this->directory) . '/' . ltrim($file, '/');
    }

    public function templatesDirectory(): string
    {
        return $this->directory . '/templates';
    }
}
