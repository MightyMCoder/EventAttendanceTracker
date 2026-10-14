<?php
/**
 * Contains the presenter that renders plugin update check results.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPluginEventAttendancePresenter;

use AdmidioInfrastructureLanguage;
use AdmidioUIPresenterPagePresenter;

/** Prepares and renders the asynchronous plugin-update result in a Smarty partial. */
final class SettingsUpdatePresenter
{
    /**
     * @param array{connected:bool,stable:array{version:string,url:string}|null,beta:array{version:string,url:string}|null} $releases
     * @return array<string,mixed>
     */
    public static function createViewModel(array $releases, string $installedVersion, Language $language): array
    {
        $viewModel = $releases;
        $viewModel['installedVersion'] = $installedVersion;

        if ($viewModel['connected']) {
            $stableAvailable = $viewModel['stable'] !== null
                && version_compare($viewModel['stable']['version'], $installedVersion, '>');
            $betaAvailable = $viewModel['beta'] !== null
                && version_compare($viewModel['beta']['version'], $installedVersion, '>');
            $viewModel['status'] = $stableAvailable || $betaAvailable
                ? $language->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_AVAILABLE')
                : $language->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_CURRENT');
            $viewModel['statusClass'] = $stableAvailable || $betaAvailable ? 'info' : 'success';
        }

        return $viewModel;
    }

    /** @param array<string,mixed> $releaseCheck */
    public static function render(PagePresenter $page, array $releaseCheck): string
    {
        $page->assignSmartyVariable('releaseCheck', $releaseCheck);

        return $page->getSmartyTemplate()->fetch('plugin.event-attendance.settings.update-status.tpl');
    }
}
