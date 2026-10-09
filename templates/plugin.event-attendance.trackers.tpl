{if $trackers|@count === 0}
    <div class="alert alert-info">{$l10n->get('SYS_EVENT_ATTENDANCE_NO_TRACKERS')}</div>
{else}
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>{$l10n->get('SYS_NAME')}</th><th>{$l10n->get('SYS_DESCRIPTION')}</th><th>{$l10n->get('SYS_STATUS')}</th></tr></thead><tbody>{foreach $trackers as $tracker}<tr><td><a href="{$tracker.url}">{$tracker.eat_name|escape}</a></td><td>{$tracker.eat_description|escape}</td><td>{if $tracker.eat_active}{$l10n->get('SYS_ENABLED')}{else}{$l10n->get('SYS_DISABLED')}{/if}</td></tr>{/foreach}</tbody></table></div>
{/if}
<a class="btn btn-primary" href="{$createUrl}"><i class="bi bi-plus-lg"></i> {$l10n->get('SYS_CREATE')}</a>
