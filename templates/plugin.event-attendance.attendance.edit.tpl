<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['attendance_lock_token']}
    <div class="table-responsive"><table id="adm_event_attendance_roster_table" class="table table-hover"><caption>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_STATUS_DESC')}</caption><thead><tr><th>{$l10n->get('SYS_NAME')}</th><th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_STATUS')}</th></tr></thead><tbody>{foreach $attendanceRows as $row}<tr><td>{$row.name|escape}</td><td>{include 'sys-template-parts/form.select.tpl' data=$elements[$row.field]}</td></tr>{/foreach}</tbody></table></div>
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_attendance']}
</form>
