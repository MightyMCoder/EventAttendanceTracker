<div class="accordion-item">
    <h2 class="accordion-header" id="plugin_information_heading">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#plugin_information">
            <i class="bi bi-info-circle-fill me-2"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_INFORMATION')}
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
                <div id="adm_event_attendance_update_status">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <code>{$installedVersion|escape}</code>
                        <button id="adm_event_attendance_check_update" class="btn btn-outline-secondary btn-sm" type="button">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>{$l10n->get('SYS_CHECK_FOR_UPDATE')}
                        </button>
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
</div>

<div class="accordion-item">
    <h2 class="accordion-header" id="deinstallation_heading">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#deinstallation">
            <i class="bi bi-trash-fill me-2"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL')}
        </button>
    </h2>
    <div id="deinstallation" class="accordion-collapse collapse" data-bs-parent="#adm_event_attendance_settings_accordion">
        <div class="accordion-body">
            <form method="post" action="{$uninstallUrl|escape}">
                <input type="hidden" name="adm_csrf_token" value="{$csrfToken|escape}">
                <p>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL_DESC')}</p>
                <button class="btn btn-danger" type="submit"><i class="bi bi-trash-fill"></i> {$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL')}</button>
            </form>
        </div>
    </div>
</div>

<script>
$('#adm_event_attendance_check_update').on('click', function () {
    const status = $('#adm_event_attendance_update_status');
    status.html('<div class="text-body-secondary"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_RUNNING')|escape:'javascript'}</div>');
    $.post('{$checkUpdateUrl|escape:'javascript'}', { adm_csrf_token: '{$csrfToken|escape:'javascript'}' }, function (data) {
        status.html(data.html);
    }).fail(function () {
        status.html('<div class="alert alert-warning mb-0">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_CONNECTION_ERROR')|escape:'javascript'}</div>');
    });
});
</script>
