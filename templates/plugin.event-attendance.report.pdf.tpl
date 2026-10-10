<table cellpadding="2" style="width:100%;">
    <tr>
        <td style="width:22%;{$logoCellStyle}">{if $logoFile !== ''}<img src="{$logoFile|escape}" width="120" />{/if}</td>
        <td style="width:78%; vertical-align:middle;"><span style="font-size:20pt;font-weight:bold;text-decoration:underline;">{$reportTitle|escape}</span></td>
    </tr>
</table>
<br />
{if $reportPeriod !== ''}<p style="font-size:13pt;font-weight:bold;">{$reportPeriod|escape}</p>{/if}
<table border="1" cellpadding="4" style="width:100%;table-layout:fixed;">
    <colgroup>
        <col style="width:16%;" />
        <col style="width:16%;" />
        <col style="width:14%;" />
        <col style="width:14%;" />
        <col style="width:14%;" />
        <col style="width:13%;" />
        <col style="width:13%;" />
    </colgroup>
    <thead>
        <tr style="background-color:#ffffff;color:#000000;font-weight:bold;">
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_PDF_LAST_NAME')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_PDF_FIRST_NAME')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_PRESENT')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_EXCUSED')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_PDF_NO_RESPONSE')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_PDF_CANCELLED')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_PDF_RATE')}</th>
        </tr>
    </thead>
    <tbody>
        {foreach $rows as $row}
            <tr>
                <td>{$row.last_name|escape}</td>
                <td>{$row.first_name|escape}</td>
                <td style="color:#00a000;text-align:center;">{$row.present_count}</td>
                <td style="color:#e87800;text-align:center;">{$row.excused_count}</td>
                <td style="color:#e00000;text-align:center;">{$row.unknown_count}</td>
                <td style="text-align:center;">{$row.cancelled_count}</td>
                <td style="color:{$row.attendance_color};text-align:right;">{$row.attendance_percentage_display} %</td>
            </tr>
        {/foreach}
    </tbody>
</table>
