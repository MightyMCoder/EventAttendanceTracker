<div class="accordion-item">
    <form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
        {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#export">
                <i class="bi bi-download me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SETTINGS_EXPORT')}
            </button>
        </h2>
        <div id="export" class="accordion-collapse collapse" data-bs-parent="#adm_event_attendance_settings_accordion">
            <div class="accordion-body">
                {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_pdf_logo_file']}
                {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_export_filename']}
                <div class="form-alert" style="display: none;" role="alert">&nbsp;</div>
                {include 'sys-template-parts/form.button.tpl' data=$elements["{$attributes.id}_save"]}
            </div>
        </div>
    </form>
</div>
