{if $logoFile !== ''}<table cellpadding="2"><tr><td><img src="{$logoFile|escape}" height="45" /></td></tr></table>{/if}
<table border="1" cellpadding="3">
    <thead>
        <tr style="background-color:#3b9db0;color:#ffffff;font-weight:bold;">
            <th>{$l10n->get('SYS_NAME')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_EXPECTED')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_PRESENT')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_EXCUSED')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_ABSENT')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_UNKNOWN')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_CANCELLED')}</th>
            <th>{$l10n->get('SYS_EVENT_ATTENDANCE_ATTENDANCE_RATE')}</th>
        </tr>
    </thead>
    <tbody>
        {foreach $rows as $row}
            <tr>
                <td>{$row.first_name|escape} {$row.last_name|escape}</td>
                <td>{$row.expected_count}</td>
                <td>{$row.present_count}</td>
                <td>{$row.excused_count}</td>
                <td>{$row.absent_count}</td>
                <td>{$row.unknown_count}</td>
                <td>{$row.cancelled_count}</td>
                <td>{if $row.completed_count > 0}{$row.attendance_percentage|string_format:'%.1f'} %{/if}</td>
            </tr>
        {/foreach}
    </tbody>
</table>
