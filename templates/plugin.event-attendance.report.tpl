<div class="alert alert-info">{$l10n->get('SYS_EVENT_ATTENDANCE_RATE_AFTER_EVENT_END')}</div>
{if $rows|@count === 0}
    <div class="alert alert-info">{$l10n->get('SYS_NO_ENTRIES')}</div>
{else}
    <div class="table-responsive">
        <table id="adm_event_attendance_report_table" class="table table-hover">
            <thead>
                <tr>
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
                        <td style="color:{$row.attendance_color};text-align:right;">{$row.attendance_percentage_display} %</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}
