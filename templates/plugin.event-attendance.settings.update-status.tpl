{if !$releaseCheck.connected}
    <div class="alert alert-warning mb-0" role="status">
        <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_CONNECTION_ERROR')}
    </div>
{else}
    <dl class="row mb-2">
        <dt class="col-sm-4">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_INSTALLED')}</dt>
        <dd class="col-sm-8"><code>{$releaseCheck.installedVersion|escape}</code></dd>
        <dt class="col-sm-4">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_STABLE')}</dt>
        <dd class="col-sm-8">
            {if $releaseCheck.stable !== null}
                <a href="{$releaseCheck.stable.url|escape}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>{$releaseCheck.stable.version|escape}</a>
            {else}
                {$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_NONE')}
            {/if}
        </dd>
        <dt class="col-sm-4">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_BETA')}</dt>
        <dd class="col-sm-8">
            {if $releaseCheck.beta !== null}
                <a href="{$releaseCheck.beta.url|escape}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>{$releaseCheck.beta.version|escape}</a>
            {else}
                {$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_NONE')}
            {/if}
        </dd>
    </dl>
    <div class="alert alert-{$releaseCheck.statusClass|escape} mb-0" role="status">{$releaseCheck.status|escape}</div>
{/if}
