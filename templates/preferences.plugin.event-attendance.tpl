<form {foreach $attributes as $attribute}
        {$attribute@key}="{$attribute}"
    {/foreach}>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['event_attendance_members_may_excuse_themselves']}
    {include 'sys-template-parts/form.select.tpl' data=$elements['event_attendance_tracker_manager_roles']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['event_attendance_sync_core_cancellation']}
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_event_attendance']}

    <div class="form-alert" style="display: none;">&nbsp;</div>
</form>
{$javascript}
