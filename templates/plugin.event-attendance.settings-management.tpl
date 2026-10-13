<div class="accordion-item">
    <h2 class="accordion-header" id="plugin_information_heading">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#plugin_information">
            <i class="bi bi-info-circle-fill me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_INFORMATION')}
        </button>
    </h2>
    <div id="plugin_information" class="accordion-collapse collapse" data-bs-parent="#adm_event_attendance_settings_accordion">
        <div class="accordion-body">
            <dl class="row">
                <dt class="col-sm-3">{$l10n->get('SYS_NAME')}</dt>
                <dd class="col-sm-9"><a href="https://github.com/MightyMCoder/EventAttendanceTracker" target="_blank" rel="noopener">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NAME')}</a></dd>
                <dt class="col-sm-3">{$l10n->get('SYS_DESCRIPTION')}</dt>
                <dd class="col-sm-9">{$pluginDescription|escape}</dd>
                <dt class="col-sm-3">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTHOR_LABEL')}</dt>
                <dd class="col-sm-9"><a href="https://github.com/MightyMCoder" target="_blank" rel="noopener">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTHOR')}</a></dd>
                <dt class="col-sm-3">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DOCUMENTATION')}</dt>
                <dd class="col-sm-9"><a href="https://github.com/MightyMCoder/EventAttendanceTracker#readme" target="_blank" rel="noopener">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_OPEN_DOCUMENTATION')}</a></dd>
            </dl>

            <div class="border-top pt-3">
                <h3 class="h6 mb-2">{$l10n->get('SYS_VERSION')}</h3>
                {if $releaseCheck !== null}
                    {if !$releaseCheck.connected}
                        <div class="alert alert-warning mb-0">
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
                        <div class="alert alert-{$releaseCheck.statusClass|escape} mb-0">{$releaseCheck.status|escape}</div>
                    {/if}
                {else}
                    <code>{$installedVersion|escape}</code>
                {/if}

                <div class="d-flex flex-wrap gap-2 mt-2">
                    <form method="post" action="{$checkUpdateUrl|escape}">
                        <input type="hidden" name="adm_csrf_token" value="{$csrfToken|escape}">
                        <button class="btn btn-outline-secondary btn-sm" type="submit">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>{$l10n->get('SYS_CHECK_FOR_UPDATE')}
                        </button>
                    </form>
                    {if $updateAvailable}
                        <form method="post" action="{$updateUrl|escape}">
                            <input type="hidden" name="adm_csrf_token" value="{$csrfToken|escape}">
                            <button class="btn btn-warning btn-sm" type="submit">
                                <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERFORM_UPDATE')}
                            </button>
                        </form>
                    {/if}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="accordion-item">
    <h2 class="accordion-header" id="deinstallation_heading">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#deinstallation">
            <i class="bi bi-trash-fill me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL')}
        </button>
    </h2>
    <div id="deinstallation" class="accordion-collapse collapse" data-bs-parent="#adm_event_attendance_settings_accordion">
        <div class="accordion-body">
            <form method="post" action="{$uninstallUrl|escape}">
                <input type="hidden" name="adm_csrf_token" value="{$csrfToken|escape}">
                <p>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL_DESC')}</p>
                <button class="btn btn-danger" type="submit"><i class="bi bi-trash-fill" aria-hidden="true"></i> {$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL')}</button>
            </form>
        </div>
    </div>
</div>
</div>
