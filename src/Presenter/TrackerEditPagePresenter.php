<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Presenter;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class TrackerEditPagePresenter extends PluginPagePresenter
{
    public static function create(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_tracker_edit', $headline, $plugin);
        $page->addDashboardNavigation($plugin);
        $page->addConfigurationNavigation($plugin);

        $page->addCurrentNavigation();

        return $page;
    }
}
