<form {foreach $attributes as $attribute}
        {$attribute@key}="{$attribute}"
    {/foreach}>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    {include 'sys-template-parts/form.separator.tpl' data=$elements['event_attendance_separator_access']}
    {include 'sys-template-parts/form.select.tpl' data=$elements['event_attendance_tracker_manager_roles']}
    {include 'sys-template-parts/form.separator.tpl' data=$elements['event_attendance_separator_attendance']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['event_attendance_sync_core_cancellation']}
    {include 'sys-template-parts/form.separator.tpl' data=$elements['event_attendance_separator_automation']}
    {include 'sys-template-parts/form.select.tpl' data=$elements['event_attendance_automation_method']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_webcron_token']}
    <div id="event_attendance_automation_disabled_section"{if $eventAttendanceAutomationMethod neq 'disabled'} style="display: none;"{/if}>
        <div class="alert alert-warning mb-3">{$eventAttendanceAutomationDisabledNotice|escape:'html'}</div>
    </div>
    <div id="event_attendance_automation_cli_section"{if $eventAttendanceAutomationMethod neq 'cli'} style="display: none;"{/if}>
        <div class="admidio-form-group row mb-3">
            <label class="col-sm-3 col-form-label">{$eventAttendanceAutomationCliLabel|escape:'html'}</label>
            <div class="col-sm-9">
                {if $eventAttendanceAutomationCliUnavailable neq ''}
                    <p class="alert alert-warning mb-0">{$eventAttendanceAutomationCliUnavailable|escape:'html'}</p>
                {else}
                    <p class="form-text mb-2">{$eventAttendanceAutomationCliHelp|escape:'html'}</p>
                    <div class="input-group">
                        <code class="form-control overflow-auto" data-automation-command>{$eventAttendanceAutomationCliCommand|escape:'html'}</code>
                        <button class="btn btn-outline-secondary text-dark" type="button" data-copy-automation-command
                            title="{$eventAttendanceCopyLabel|escape:'html'}" aria-label="{$eventAttendanceCopyLabel|escape:'html'}">
                            <i class="bi bi-copy" aria-hidden="true"></i>
                        </button>
                    </div>
                {/if}
            </div>
        </div>
    </div>
    <div id="event_attendance_automation_webcron_section"{if $eventAttendanceAutomationMethod neq 'webcron'} style="display: none;"{/if}>
        <div class="admidio-form-group row mb-3">
            <label class="col-sm-3 col-form-label">{$eventAttendanceAutomationWebcronLabel|escape:'html'}</label>
            <div class="col-sm-9">
                <p class="form-text mb-2">{$eventAttendanceAutomationWebcronHelp|escape:'html'}</p>
                <div class="input-group">
                    <code class="form-control overflow-auto" data-automation-command>{$eventAttendanceAutomationWebcronUrl|escape:'html'}</code>
                    <button class="btn btn-outline-secondary text-dark" type="button" data-copy-automation-command
                        title="{$eventAttendanceCopyLabel|escape:'html'}" aria-label="{$eventAttendanceCopyLabel|escape:'html'}">
                        <i class="bi bi-copy" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div id="event_attendance_automation_last_run_section"{if $eventAttendanceAutomationMethod eq 'disabled'} style="display: none;"{/if}>
        <div class="offset-sm-3 mb-3">
            <span class="badge text-bg-{$eventAttendanceAutomationLastRun.status|escape:'html'} me-2">
                <i class="bi bi-circle-fill" aria-hidden="true"></i>
                {$eventAttendanceAutomationLastRun.statusText|escape:'html'}
            </span>
            {$eventAttendanceAutomationLastRun.lastRunText|escape:'html'}
        </div>
    </div>
    {include 'sys-template-parts/form.separator.tpl' data=$elements['event_attendance_separator_rules']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['event_attendance_members_may_excuse_themselves']}
    <div id="event_attendance_self_excuse_rules">
        {include 'sys-template-parts/form.select.tpl' data=$elements['event_attendance_self_excuse_roles']}
        {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_self_excuse_deadline_hours']}
    </div>
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['event_attendance_rate_counts_excused']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_rate_decimal_places']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_rate_red_threshold']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_rate_orange_threshold']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_recording_lock_days']}
    {include 'sys-template-parts/form.separator.tpl' data=$elements['event_attendance_separator_export']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_pdf_logo_file']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_export_filename']}
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_event_attendance']}

    <div class="form-alert" style="display: none;">&nbsp;</div>
</form>
{$javascript}
<script>
$(function () {
    const managerRoles = [{foreach $eventAttendanceManagerRoles as $roleId}'{$roleId|escape:'javascript'}'{if not $roleId@last},{/if}{/foreach}];
    const selfExcuseRoles = [{foreach $eventAttendanceSelfExcuseRoles as $roleId}'{$roleId|escape:'javascript'}'{if not $roleId@last},{/if}{/foreach}];
    const managerRolesSelect = $('#event_attendance_tracker_manager_roles');
    const select2Language = (document.documentElement.lang || 'en').split('-')[0];

    const initializeManagerRoles = function () {
        if (typeof $.fn.select2 !== 'function' || managerRolesSelect.hasClass('select2-hidden-accessible')) {
            return;
        }

        const select2Options = {
            theme: 'bootstrap-5',
            allowClear: false,
            language: select2Language
        };
        const modal = managerRolesSelect.closest('.modal');
        if (modal.length > 0) {
            select2Options.dropdownParent = modal;
        }
        managerRolesSelect.select2(select2Options);
        managerRolesSelect.val(managerRoles).trigger('change.select2');
    };

    const loadSelect2 = function () {
        if (typeof $.fn.select2 === 'function') {
            initializeManagerRoles();
            return;
        }

        const select2Path = gRootPath + '/libs/select2';
        if (!document.getElementById('event_attendance_select2_css')) {
            $('<link>', {
                id: 'event_attendance_select2_css',
                rel: 'stylesheet',
                href: select2Path + '/css/select2.css'
            }).appendTo('head');
            $('<link>', {
                id: 'event_attendance_select2_bootstrap_css',
                rel: 'stylesheet',
                href: gRootPath + '/libs/select2-bootstrap-theme/select2-bootstrap-5-theme.css'
            }).appendTo('head');
        }

        $.getScript(select2Path + '/js/select2.js').done(function () {
            $.getScript(select2Path + '/js/i18n/' + select2Language + '.js').always(initializeManagerRoles);
        });
    };

    loadSelect2();
    managerRolesSelect.val(managerRoles).trigger('change');
    $('#event_attendance_self_excuse_roles').val(selfExcuseRoles).trigger('change');

    const updateSelfExcuseRules = function () {
        $('#event_attendance_self_excuse_rules').toggle($('#event_attendance_members_may_excuse_themselves').is(':checked'));
    };
    $('#event_attendance_members_may_excuse_themselves').on('change', updateSelfExcuseRules);
    updateSelfExcuseRules();

    const updateAutomationMethod = function (direct) {
        const method = $('#event_attendance_automation_method').val();
        const toggleSection = function (selector, show) {
            const section = $(selector);
            if (direct) {
                section.toggle(show);
            } else if (show) {
                section.stop(true, true).slideDown('slow');
            } else {
                section.stop(true, true).slideUp('slow');
            }
        };
        toggleSection('#event_attendance_automation_cli_section', method === 'cli');
        toggleSection('#event_attendance_automation_webcron_section', method === 'webcron');
        toggleSection('#event_attendance_automation_disabled_section', method === 'disabled');
        toggleSection('#event_attendance_automation_last_run_section', method !== 'disabled');
    };

    $('#event_attendance_automation_method').on('change', function () { updateAutomationMethod(false); });
    updateAutomationMethod(true);

    $('[data-copy-automation-command]').on('click', function () {
        const button = $(this);
        const command = button.siblings('[data-automation-command]').text();
        const initialContent = button.html();
        if (!navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(command).then(function () {
            button.html('<i class="bi bi-clipboard-check"></i>');
            window.setTimeout(function () { button.html(initialContent); }, 1500);
        }).catch(function () {});
    });
});
</script>
