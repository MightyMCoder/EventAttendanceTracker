<div class="accordion-item">
    <form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
        {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#attendance_locks">
                <i class="bi bi-lock me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCKS')}
            </button>
        </h2>
        <div id="attendance_locks" class="accordion-collapse collapse" data-bs-parent="#adm_event_attendance_settings_accordion">
            <div class="accordion-body">
                {include 'sys-template-parts/form.checkbox.tpl' data=$elements['event_attendance_attendance_lock_enabled']}
                <div id="adm_event_attendance_lock_settings"{if !$attendanceLockEnabled} style="display: none;"{/if}>
                    {include 'sys-template-parts/form.input.tpl' data=$elements['event_attendance_attendance_lock_duration_minutes']}
                    <div id="adm_event_attendance_lock_cleanup">
                        <p>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCKS_DESC')}</p>
                        <div id="adm_event_attendance_locks_cleared" class="alert alert-success d-none" role="status">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCKS_CLEARED')}</div>
                        <button id="adm_event_attendance_clear_locks" class="btn btn-warning mb-3" type="button"><i class="bi bi-unlock me-1" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_ATTENDANCE_LOCKS_CLEAR')}</button>
                    </div>
                </div>
                {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_event_attendance']}
            </div>
        </div>
    </form>
</div>

<script>
$(function () {
    $('#adm_event_attendance_clear_locks').on('click', function () {
        $.post('{$clearAttendanceLocksUrl|escape:'javascript'}', { adm_csrf_token: '{$csrfToken|escape:'javascript'}' }, function (response) {
            const result = typeof response === 'object' ? response : JSON.parse(response);
            if (result.status === 'success') {
                $('#adm_event_attendance_locks_cleared').removeClass('d-none');
            }
        });
    });
});
</script>
