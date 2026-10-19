<div id="{$data.id}_group" class="admidio-form-group row mb-3">
    {include 'sys-template-parts/parts/form.part.fieldtoggle.tpl'}
    <div class="col-sm-9">
        <select id="{$data.id}" class="form-select focus-ring {$data.class}"
            {foreach $data.attributes as $itemvar}
                {$itemvar@key}="{$itemvar}"
            {/foreach}>
            <option value="" {if $data.defaultValue eq ''}selected="selected"{/if} hidden="hidden" disabled="disabled">{$data.label}</option>
            {foreach $data.values as $optionvar}
                {if $optionvar["id"] neq ""}
                    <option value="{$optionvar["id"]}" {if $data.defaultValue eq $optionvar["id"]}selected="selected"{/if}>{$optionvar["value"]}</option>
                {/if}
            {/foreach}
        </select>
        {include 'sys-template-parts/parts/form.part.helptext.tpl'}
        {include 'sys-template-parts/parts/form.part.warning.tpl'}
    </div>
</div>
