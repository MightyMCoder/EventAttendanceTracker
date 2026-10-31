{if $installationSuccessful}
    <div class="alert alert-success" role="status">{$l10n->get('SYS_INSTALLATION_SUCCESSFUL')}</div>
{/if}

<div class="accordion" id="adm_event_attendance_settings_accordion">
    <div class="accordion-item">
        <form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
            {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
            <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#access">
                    <i class="bi bi-key me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SETTINGS_ACCESS')}
                </button>
            </h2>
            <div id="access" class="accordion-collapse collapse show" data-bs-parent="#adm_event_attendance_settings_accordion">
                <div class="accordion-body">
                    {include 'sys-template-parts/form.select.tpl' data=$elements['event_attendance_tracker_manager_roles']}
                    {include 'sys-template-parts/form.select.tpl' data=$elements['event_attendance_attendance_recorder_roles']}
                    <div class="form-alert" style="display: none;" role="alert">&nbsp;</div>
                    {include 'sys-template-parts/form.button.tpl' data=$elements["{$attributes.id}_save"]}
                </div>
            </div>
        </form>
    </div>
