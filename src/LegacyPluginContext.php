<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance;

/** Provides settings and URLs for the legacy Admidio 5 plugin layout. */
final class LegacyPluginContext
{
    private static ?self $instance = null;

    /** @param array<string,mixed> $settings */
    private function __construct(private readonly string $directory, private readonly array $settings)
    {
    }

    public static function initialize(string $directory): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $defaults = require $directory . '/config_sample.php';
        $configured = is_file($directory . '/config.php') ? require $directory . '/config.php' : array();
        if (!is_array($configured)) {
            throw new \UnexpectedValueException('The event-attendance config.php must return an array.');
        }

        return self::$instance = new self($directory, array_replace($defaults, $configured));
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
        return ADMIDIO_URL . FOLDER_PLUGINS . '/' . basename($this->directory) . '/modules/' . ltrim($file, '/');
    }

    public function templatesDirectory(): string
    {
        return $this->directory . '/templates';
    }
}
