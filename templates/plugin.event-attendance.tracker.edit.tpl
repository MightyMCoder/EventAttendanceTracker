<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['tracker_name']}
    {include 'sys-template-parts/form.multiline.tpl' data=$elements['tracker_description']}
    {include 'sys-template-parts/form.select.tpl' data=$elements['tracker_roles']}
    {include 'sys-template-parts/form.select.tpl' data=$elements['tracker_events']}
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_tracker']}
</form>
