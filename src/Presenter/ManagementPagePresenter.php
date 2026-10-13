<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Presenter;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

final class ManagementPagePresenter extends PluginPagePresenter
{
    public static function create(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createPage('adm_event_attendance_manage', $headline, $plugin);
    }
}
