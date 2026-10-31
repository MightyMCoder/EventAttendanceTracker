<div class="accordion-item">
    <h2 class="accordion-header" id="plugin_information_heading">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#plugin_information">
            <i class="bi bi-info-circle me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PLUGIN_INFORMATION')}
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
                    <code>{$installedVersion|escape}</code>
                    <a id="adm_event_attendance_check_update" href="{$checkUpdateUrl|escape}">{$l10n->get('SYS_CHECK_FOR_UPDATE')}</a>
                </div>
                <div id="adm_event_attendance_update_pending" class="d-none text-body-secondary">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_RUNNING')}
                </div>
                <div id="adm_event_attendance_update_error" class="alert alert-warning d-none mt-2 mb-0" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_CHECK_CONNECTION_ERROR')}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="accordion-item">
    <h2 class="accordion-header" id="deinstallation_heading">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#deinstallation">
            <i class="bi bi-trash me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UNINSTALL')}
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

<script>
$('#adm_event_attendance_check_update').on('click', function (event) {
    event.preventDefault();
    const status = $('#adm_event_attendance_update_status');
    const pending = $('#adm_event_attendance_update_pending');
    const error = $('#adm_event_attendance_update_error');
    const url = $(this).attr('href');

    status.addClass('d-none');
    pending.removeClass('d-none');
    error.addClass('d-none');

    $.ajax({ url: url, type: 'POST', dataType: 'json', data: { adm_csrf_token: '{$csrfToken|escape:'javascript'}' } }).done(function (data) {
        if (typeof data.html === 'string') {
            status.html(data.html);
        } else {
            error.removeClass('d-none');
        }
        status.removeClass('d-none');
        pending.addClass('d-none');
    }).fail(function () {
        pending.addClass('d-none');
        error.removeClass('d-none');
        status.removeClass('d-none');
    });
});
</script>
