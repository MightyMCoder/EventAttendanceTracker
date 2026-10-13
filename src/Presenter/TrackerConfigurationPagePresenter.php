<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Presenter;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class TrackerConfigurationPagePresenter extends PluginPagePresenter
{
    public static function create(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_configuration', $headline, $plugin);
        $page->addDashboardNavigation($plugin);

        $page->addCurrentNavigation();

        return $page;
    }
}
