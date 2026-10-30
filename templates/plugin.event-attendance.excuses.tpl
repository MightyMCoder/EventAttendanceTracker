<h2 class="mt-4"><i class="bi bi-person-check me-2" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSES')}</h2>
<p>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSES_DESC')}</p>
<p class="text-body-secondary">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSES_SAVE_HINT')}</p>
    <input type="hidden" id="tracker_permanent_excuses" name="tracker_permanent_excuses" value="{$elements['tracker_permanent_excuses'].value|escape}">
    <div class="table-responsive">
        <table id="adm_event_attendance_excuses_table" class="table table-hover">
            <thead><tr>
                <th class="all">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_PERSON')}</th>
                <th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_FROM')}</th>
                <th>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_UNTIL')}</th>
                <th class="all text-end">&nbsp;</th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <button id="adm_event_attendance_add_excuse" type="button" class="btn btn-primary mb-3"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_ADD')}</button>
<template id="adm_event_attendance_excuse_row">
    <tr>
        <td class="excuse-name"></td>
        <td class="excuse-from text-nowrap"></td>
        <td class="excuse-until text-nowrap"></td>
        <td class="text-end text-nowrap">{include 'sys-template-parts/list.functions.tpl' data=$permanentExcuseActions}</td>
    </tr>
</template>
