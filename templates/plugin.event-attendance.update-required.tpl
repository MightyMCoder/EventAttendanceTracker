<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>{$l10n->get('SYS_EVENT_ATTENDANCE_UPDATE_REQUIRED')}
</div>
<div class="card">
    <div class="card-body">
        <h2 class="card-title h4">{$l10n->get('SYS_EVENT_ATTENDANCE_PERFORM_UPDATE')}</h2>
        <p class="card-text">{$l10n->get('SYS_EVENT_ATTENDANCE_UPDATE_REQUIRED_DESC')}</p>
        <dl class="row mb-4">
            <dt class="col-sm-4">{$l10n->get('SYS_EVENT_ATTENDANCE_INSTALLED_VERSION')}</dt>
            <dd class="col-sm-8"><code>{$installedVersion|escape}</code></dd>
            <dt class="col-sm-4">{$l10n->get('SYS_EVENT_ATTENDANCE_AVAILABLE_VERSION')}</dt>
            <dd class="col-sm-8"><code>{$availableVersion|escape}</code></dd>
        </dl>
        <form method="post" action="{$updateUrl|escape}">
            <input type="hidden" name="adm_csrf_token" value="{$csrfToken|escape}">
            <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat"></i> {$l10n->get('SYS_EVENT_ATTENDANCE_PERFORM_UPDATE')}</button>
        </form>
    </div>
</div>
