<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Presenter;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class SelfExcusePagePresenter extends PluginPagePresenter
{
    public static function create(LegacyPluginContext $plugin, string $headline): self
    {
        $page = self::createPage('adm_event_attendance_self_excuse', $headline, $plugin);
        $page->addDashboardNavigation($plugin);

        $page->addCurrentNavigation();

        return $page;
    }
}
