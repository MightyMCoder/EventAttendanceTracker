<div id="adm_event_attendance_excuse_editor" class="modal fade" tabindex="-1" aria-labelledby="adm_event_attendance_excuse_modal_title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form id="adm_event_attendance_excuse_editor_form" class="modal-content">
            <div class="modal-header">
                <h5 id="adm_event_attendance_excuse_modal_title" class="modal-title">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSES')}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{$l10n->get('SYS_CLOSE')}"></button>
            </div>
                <div class="modal-body">
                    {include 'sys-template-parts/form.input.tpl' data=$elements['excuse_id']}
                    {include 'sys-template-parts/form.select.tpl' data=$elements['excuse_user'] formType='vertical'}
                    {include 'sys-template-parts/form.input.tpl' data=$elements['excuse_from'] formType='vertical'}
                    {include 'sys-template-parts/form.input.tpl' data=$elements['excuse_until'] formType='vertical'}
                    <p class="text-body-secondary mb-0">{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSES_SAVE_HINT')}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$l10n->get('SYS_CANCEL')}</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>{$l10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_APPLY')}</button>
                </div>
        </form>
    </div>
</div>
