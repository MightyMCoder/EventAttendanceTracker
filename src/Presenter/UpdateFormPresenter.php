<?php

declare(strict_types=1);

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
