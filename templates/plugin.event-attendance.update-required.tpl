<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_REQUIRED')}
</div>
<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    <div class="card">
        <div class="card-body">
            <h2 class="card-title h4">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERFORM_UPDATE')}</h2>
            <p class="card-text">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_UPDATE_REQUIRED_DESC')}</p>
            <dl class="row mb-4">
                <dt class="col-sm-4">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_INSTALLED_VERSION')}</dt>
                <dd class="col-sm-8"><code>{$installedVersion|escape}</code></dd>
                <dt class="col-sm-4">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AVAILABLE_VERSION')}</dt>
                <dd class="col-sm-8"><code>{$availableVersion|escape}</code></dd>
            </dl>
            <div class="form-alert" style="display: none;">&nbsp;</div>
            {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_update_event_attendance']}
        </div>
    </div>
</form>
