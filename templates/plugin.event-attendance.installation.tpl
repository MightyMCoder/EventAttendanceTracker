{if $resultMessage ne ''}<div class="alert alert-success">{$resultMessage|escape}</div>{/if}
<div class="card">
    <div class="card-body">
        <h2 class="card-title h4">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NAME')}</h2>
        <p class="card-text">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DESCRIPTION')}</p>
        <dl class="row mb-4">
            <dt class="col-sm-3">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTHOR_LABEL')}</dt>
            <dd class="col-sm-9"><a href="https://github.com/MightyMCoder" target="_blank" rel="noopener">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTHOR')}</a></dd>
            <dt class="col-sm-3">{$l10n->get('SYS_VERSION')}</dt>
            <dd class="col-sm-9"><code>{$availableVersion|escape}</code></dd>
            <dt class="col-sm-3">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_DOCUMENTATION')}</dt>
            <dd class="col-sm-9"><a href="https://github.com/MightyMCoder/EventAttendanceTracker#readme" target="_blank" rel="noopener">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_OPEN_DOCUMENTATION')}</a></dd>
        </dl>
        <div class="alert alert-warning">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_NOT_INSTALLED')}</div>
        <form method="post" action="{$installUrl|escape}">
            <input type="hidden" name="adm_csrf_token" value="{$csrfToken|escape}">
            <button class="btn btn-primary" type="submit"><i class="bi bi-download"></i> {$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_INSTALL')}</button>
        </form>
    </div>
</div>
