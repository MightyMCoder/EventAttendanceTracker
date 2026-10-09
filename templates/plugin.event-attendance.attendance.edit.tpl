<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    <div class="table-responsive"><table class="table"><thead><tr><th>{$l10n->get('SYS_NAME')}</th><th>{$l10n->get('SYS_STATUS')}</th></tr></thead><tbody>{foreach $attendanceRows as $row}<tr><td>{$row.name|escape}</td><td>{include 'sys-template-parts/form.select.tpl' data=$elements[$row.field]}</td></tr>{/foreach}</tbody></table></div>
    <div class="form-alert" style="display: none;">&nbsp;</div>{include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_attendance']}<button class="btn btn-secondary" type="submit" formaction="{$cancelUrl}">{$l10n->get('SYS_CANCELLED')}</button>
</form>
