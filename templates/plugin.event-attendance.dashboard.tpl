{if $occurrences|@count === 0}
    <div class="alert alert-info">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NO_OCCURRENCES')}</div>
{else}
    <div class="table-responsive">
        <table id="adm_event_attendance_dashboard_table" class="table table-hover">
            <thead><tr><th>{$l10n->get('SYS_EVENTS')}</th><th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_TRACKERS')}</th><th>{$l10n->get('SYS_DATE')}</th><th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_STATUS')}</th><th>&nbsp;</th></tr></thead>
            <tbody>{foreach $occurrences as $occurrence}<tr id="adm_event_attendance_occurrence_{$occurrence.uuid}"><td>{$occurrence.dat_headline|escape}</td><td>{$occurrence.eat_name|escape}</td><td>{$occurrence.dat_begin|escape}</td><td>{$occurrence.status|escape}</td><td class="text-end">{if $occurrence.hasActions}{include 'sys-template-parts/list.functions.tpl' data=$occurrence}{/if}</td></tr>{/foreach}</tbody>
        </table>
    </div>
{/if}
