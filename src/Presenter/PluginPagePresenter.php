<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 */


namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;

/** Base presenter for complete Event Attendance Tracker pages. */
abstract class PluginPagePresenter extends PagePresenter
{
    protected static function createPage(string $htmlId, string $headline, LegacyPluginContext $plugin): static
    {
        $page = new static();
        $page->setHtmlID($htmlId);
        $page->setHeadline($headline);
        $page->addTemplateFolder($plugin->templatesDirectory());

        return $page;
    }

    protected function addDashboardNavigation(LegacyPluginContext $plugin): void
    {
        global $gL10n, $gNavigation;

        $gNavigation->addStartUrl(
            $plugin->getUrl('index.php'),
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_MANAGE'),
            'bi-person-check-fill'
        );
    }

    protected function addConfigurationNavigation(LegacyPluginContext $plugin): void
    {
        global $gL10n, $gNavigation;

        $gNavigation->addUrl(
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'config')),
            $gL10n->get('SYS_CONFIGURATION')
        );
    }

    public function addCurrentNavigation(): void
    {
        global $gNavigation;

        $gNavigation->addUrl(CURRENT_URL, $this->getHeadline());
    }

    public function addNavigation(string $url, string $label): void
    {
        global $gNavigation;

        $gNavigation->addUrl($url, $label);
    }
}
