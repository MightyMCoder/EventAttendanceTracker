{if $trackers|@count === 0}
    <div class="alert alert-info">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NO_TRACKERS')}</div>
{else}
    <div class="list-group">
        {foreach $trackers as $tracker}
            <a class="list-group-item list-group-item-action" href="{$tracker.reportUrl}">
                <i class="bi bi-bar-chart-fill"></i> {$tracker.eat_name|escape}
                {if $tracker.eat_description !== ''}<small class="d-block text-body-secondary">{$tracker.eat_description|escape}</small>{/if}
            </a>
        {/foreach}
    </div>
{/if}
