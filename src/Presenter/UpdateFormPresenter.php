<?php
/**
 * Contains the presenter that builds the plugin update form.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\LegacyPluginContext;

/** Renders the mandatory plugin update as a regular Admidio form. */
final class UpdateFormPresenter
{
    public function create(PagePresenter $page, LegacyPluginContext $plugin): void
    {
        global $gCurrentSession, $gL10n;

        $form = new FormPresenter(
            'adm_event_attendance_update_form',
            'plugin.event-attendance.update-required.tpl',
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'update')),
            $page,
            array('setFocus' => false, 'showRequiredFields' => false)
        );
        $form->addSubmitButton(
            'adm_button_update_event_attendance',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERFORM_UPDATE'),
            array('icon' => 'bi-arrow-repeat', 'class' => 'offset-sm-3')
        );
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }
}
