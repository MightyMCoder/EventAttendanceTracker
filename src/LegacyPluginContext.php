<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\EventAttendance\Service\LegacySettingsService;

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

        $settings = (new LegacySettingsService($database))->get($organizationId, PluginDefaults::settings());

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
        // Keep existing internal callers and bookmarks compatible with the renamed entry point.
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
