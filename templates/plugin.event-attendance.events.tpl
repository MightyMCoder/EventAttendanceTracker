{if $occurrences|@count === 0}
    <div class="alert alert-info">{$l10n->get('SYS_NO_ENTRIES')}</div>
{else}
    <div class="table-responsive"><table id="adm_event_attendance_events_table" class="table table-hover"><thead><tr><th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_EVENT')}</th><th>{$l10n->get('SYS_DATE')}</th><th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_STATUS')}</th></tr></thead><tbody>{foreach $occurrences as $occurrence}<tr><td><a href="{$occurrence.url}">{$occurrence.dat_headline|escape}</a></td><td>{$occurrence.dat_begin|escape}</td><td>{if $occurrence.cancelled}{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_CANCELLED')}{else}{$l10n->get('SYS_ENABLED')}{/if}</td></tr>{/foreach}</tbody></table></div>
{/if}
