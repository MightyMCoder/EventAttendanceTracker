<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Presenter;

use AdmidioPlugin\EventAttendance\LegacyPluginContext;

/** Provides the Smarty context used to render the PDF report document. */
final class PdfReportPagePresenter extends PluginPagePresenter
{
    public static function create(LegacyPluginContext $plugin, string $headline): self
    {
        return self::createPage('adm_event_attendance_report_pdf', $headline, $plugin);
    }
}
