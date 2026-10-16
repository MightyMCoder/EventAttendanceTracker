<div class="accordion-item">
    <form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
        {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#automation">
                {$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_SETTINGS_AUTOMATION')}
            </button>
        </h2>
        <div id="automation" class="accordion-collapse collapse" data-bs-parent="#adm_event_attendance_settings_accordion">
            <div class="accordion-body">
                {include 'sys-template-parts/form.select.tpl' data=$elements['event_attendance_automation_method']}

                <div id="event_attendance_automation_disabled_notice" class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_DISABLED_NOTICE')}
                </div>

                <div id="event_attendance_automation_cli_section">
                    <h3 class="h6">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_CLI')}</h3>
                    {if $automationCliCommand}
                        <p class="form-text mb-2">{$automationCliHelp|escape}</p>
                        <div class="input-group">
                            <code class="form-control overflow-auto" data-automation-command>{$automationCliCommand|escape}</code>
                            <button class="btn btn-outline-secondary text-dark" type="button" data-copy-automation-command title="{$automationCopyLabel|escape}" aria-label="{$automationCopyLabel|escape}">
                                <i class="bi bi-copy" aria-hidden="true"></i>
                            </button>
                        </div>
                    {else}
                        <p class="alert alert-warning mb-0">{$automationCliUnavailableMessage|escape}</p>
                    {/if}
                </div>

                <div id="event_attendance_automation_webcron_section">
                    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_webcron_token']}
                    <h3 class="h6">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_METHOD_WEBCRON')}</h3>
                    <p class="form-text mb-2">{$automationWebcronHelp|escape}</p>
                    <div class="input-group">
                        <code class="form-control overflow-auto" data-automation-command>{$automationWebcronCommand|escape}</code>
                        <button class="btn btn-outline-secondary text-dark" type="button" data-copy-automation-command title="{$automationCopyLabel|escape}" aria-label="{$automationCopyLabel|escape}">
                            <i class="bi bi-copy" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div id="event_attendance_automation_status_section">
                    <h3 class="h6">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS')}</h3>
                    <span class="badge text-bg-{$automationStatus.class|escape} me-2">
                        <i class="bi bi-circle-fill" aria-hidden="true"></i> {$automationStatus.text|escape}
                    </span>
                    {$automationStatus.lastRun|escape}
                </div>

                {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_event_attendance']}
            </div>
        </div>
    </form>
</div>

<script>
$(function () {
    const updateAutomation = function () {
        const method = $('#event_attendance_automation_method').val();
        $('#event_attendance_automation_disabled_notice').toggle(method === 'disabled');
        $('#event_attendance_automation_cli_section').toggle(method === 'cli');
        $('#event_attendance_automation_webcron_section').toggle(method === 'webcron');
        $('#event_attendance_automation_status_section').toggle(method !== 'disabled');
    };

    $('#event_attendance_automation_method').on('change', updateAutomation);
    updateAutomation();

    $('[data-copy-automation-command]').on('click', function () {
        const button = $(this);
        const command = button.siblings('[data-automation-command]').text();
        const icon = button.find('i');
        if (!navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(command).then(function () {
            icon.removeClass('bi-copy').addClass('bi-clipboard-check');
            window.setTimeout(function () {
                icon.removeClass('bi-clipboard-check').addClass('bi-copy');
            }, 1500);
        }).catch(function () {});
    });
});
</script>
