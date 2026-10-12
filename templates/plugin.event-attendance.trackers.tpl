{if $trackers|@count === 0}
    <div class="alert alert-info">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NO_TRACKERS')}</div>
{else}
    <div class="table-responsive">
        <table id="adm_event_attendance_trackers_table" class="table table-hover">
            <thead><tr><th><span class="visually-hidden">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_STATUS')}</span></th><th>{$l10n->get('SYS_NAME')}</th><th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_TRACKER_SOURCE')}</th><th>{$l10n->get('SYS_DESCRIPTION')}</th><th>&nbsp;</th></tr></thead>
            <tbody>{foreach $trackers as $tracker}<tr id="adm_event_attendance_tracker_{$tracker.uuid}" data-uuid="{$tracker.uuid}" data-status="{if $tracker.eat_active}active{else}inactive{/if}" data-edit-url="{$tracker.editUrl|escape}" data-activate-url="{$tracker.activateUrl|escape}" data-deactivate-url="{$tracker.deactivateUrl|escape}" data-activate-message="{$tracker.activateMessage|escape}" data-deactivate-message="{$tracker.deactivateMessage|escape}"><td class="adm_event_attendance_tracker_status">{if $tracker.eat_active}<i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i><span class="visually-hidden">{$l10n->get('SYS_ENABLED')}</span>{else}<i class="bi bi-slash-circle text-secondary" aria-hidden="true"></i><span class="visually-hidden">{$l10n->get('SYS_DISABLED')}</span>{/if}</td><td class="adm_event_attendance_tracker_content{if !$tracker.eat_active} admidio-opacity-reduced{/if}">{$tracker.eat_name|escape}</td><td class="adm_event_attendance_tracker_content{if !$tracker.eat_active} admidio-opacity-reduced{/if}">{$tracker.source|escape}</td><td class="adm_event_attendance_tracker_content{if !$tracker.eat_active} admidio-opacity-reduced{/if}">{$tracker.eat_description|escape}</td><td class="text-end">{include 'sys-template-parts/list.functions.tpl' data=$tracker}</td></tr>{/foreach}</tbody>
        </table>
    </div>
{/if}
