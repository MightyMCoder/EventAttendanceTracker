<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 loader for Admidio 5 plugins.
 *
 * Admidio 5 does not discover a plugin's composer/autoload definition. Keep this
 * loader local to the plugin so the core installation stays untouched.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'AdmidioPlugin\\EventAttendance\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
