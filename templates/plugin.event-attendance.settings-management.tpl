<section class="mt-4">
    <h2>{$l10n->get('SYS_EVENT_ATTENDANCE_PLUGIN_MANAGEMENT')}</h2>
    <p>{$l10n->get('SYS_EVENT_ATTENDANCE_INSTALLED_VERSION')}: <code>{$installedVersion|escape}</code></p>
    <div class="alert alert-{if $updateAvailable}warning{else}success{/if}">
        {if $updateAvailable}{$l10n->get('SYS_EVENT_ATTENDANCE_UPDATE_AVAILABLE')}{else}{$l10n->get('SYS_EVENT_ATTENDANCE_UP_TO_DATE')}{/if}
    </div>
    <form class="d-inline" method="post" action="{$updateUrl|escape}">
        <input type="hidden" name="adm_csrf_token" value="{$csrfToken|escape}">
        <button class="btn btn-secondary" type="submit"><i class="bi bi-arrow-repeat"></i> {$l10n->get('SYS_EVENT_ATTENDANCE_PERFORM_UPDATE')}</button>
    </form>
    <form class="d-inline" method="post" action="{$uninstallUrl|escape}">
        <input type="hidden" name="adm_csrf_token" value="{$csrfToken|escape}">
        <button class="btn btn-danger" type="submit"><i class="bi bi-trash-fill"></i> {$l10n->get('SYS_EVENT_ATTENDANCE_UNINSTALL')}</button>
    </form>
</section>
