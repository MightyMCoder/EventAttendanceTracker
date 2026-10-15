<?php

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;

final class SelfExcusePresenter
{
    /** @param array<int,array<string,mixed>> $events */
    public function create(PagePresenter $page, Plugin $plugin, array $events): void
    {
        global $gCurrentSession, $gL10n;

        $options = array();
        $selected = array();
        foreach ($events as $event) {
            $id = $event['eat_uuid'] . ':' . $event['dat_uuid'];
            $options[$id] = $event['dat_begin'] . ' — ' . $event['eat_name'] . ' — ' . $event['dat_headline'];
            if ($event['ear_status'] === 'excused') {
                $selected[] = $id;
            }
        }

        $form = new FormPresenter(
            'adm_event_attendance_self_excuse_form',
            'plugin.event-attendance.self-excuse.tpl',
            SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array('mode' => 'save_self_excuses')),
            $page
        );
        $form->addSelectBox(
            'self_excuse_events',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SELECT_EVENTS_TO_EXCUSE'),
            $options,
            array('defaultValue' => $selected, 'multiselect' => true, 'showContextDependentFirstEntry' => false, 'helpTextId' => 'PLG_EVENT_ATTENDANCE_TRACKER_SELF_EXCUSE_EVENTS_DESC')
        );
        $form->addSubmitButton(
            'adm_button_save_self_excuses',
            $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EXCUSE_SELF'),
            array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')
        );
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }
}
