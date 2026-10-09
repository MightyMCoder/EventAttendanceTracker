<?php

namespace AdmidioPlugin\EventAttendance;

use Admidio\Hooks\Hooks;
use Admidio\Infrastructure\Plugins\PluginPanel;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use AdmidioPlugin\EventAttendance\Presenter\EventAttendancePreferencesPresenter;

/**
 * Registers the extensions contributed by the event-attendance plugin.
 */
final class EventAttendance
{
    public const PLUGIN_ID = 'event-attendance';

    private function __construct()
    {
    }

    /**
     * Register the custom preferences panel needed for the roles multiselect.
     */
    public static function register(): void
    {
        $plugin = PluginRegistry::get(self::PLUGIN_ID);
        if ($plugin === null) {
            return;
        }

        Hooks::addFilter(
            PluginPanel::HOOK,
            static function (array $panels) use ($plugin): array {
                global $gL10n;

                $panels[] = array(
                    'id' => PluginPanel::normalizeId($plugin->id),
                    'title' => $gL10n->get($plugin->name),
                    'icon' => $plugin->icon,
                    'create' => array(EventAttendancePreferencesPresenter::class, 'createForm')
                );

                return $panels;
            },
            PluginPanel::DEFAULT_SEQUENCE,
            1,
            $plugin->id
        );
    }
}
