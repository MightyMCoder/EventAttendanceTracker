{if $selfExcuseEvents|@count === 0}
    <div class="alert alert-info">{$l10n->get('SYS_NO_ENTRIES')}</div>
{else}
    <div class="table-responsive">
        <table id="adm_event_attendance_self_excuse_table" class="table table-hover">
            <thead><tr><th>{$l10n->get('SYS_EVENTS')}</th><th>{$l10n->get('SYS_DATE')}</th><th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_STATUS')}</th><th>&nbsp;</th></tr></thead>
            <tbody>{foreach $selfExcuseEvents as $event}<tr><td>{$event.headline|escape}</td><td>{$event.begin|escape}</td><td>{$event.status|escape}</td><td class="text-end">{include 'sys-template-parts/list.functions.tpl' data=$event}</td></tr>{/foreach}</tbody>
        </table>
    </div>
{/if}
