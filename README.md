# EventAttendanceTracker

Private Admidio 6.0 plugin for configurable attendance trackers.

## Local installation

Copy or clone this repository as `plugins/event-attendance` below an Admidio installation. Then
enable it in **Administration → Extensions**. The plugin manager runs `db_scripts/install.sql` and
registers the plugin settings for every organization.

The plugin requires Admidio 6.0 and PHP 8.2.

## Automatic absent statuses

Enable **Automatically mark overdue attendance as absent** in the plugin preferences to let a
scheduled task snapshot expected attendees at event start and mark remaining open records as
`absent` after the event ends. The scheduler is deliberately external to the web request:

- **CLI (recommended):** run the command shown in the preferences every 15 minutes as an Admidio
  administrator.
- **Webcron:** configure the protected URL shown in the preferences in an external scheduler. The
  URL includes a secret token and must be handled like a password.

Disabling the preference makes both triggers perform no changes. The plugin never installs or
modifies a system cronjob itself.

See `PLAN.md` for the functional scope and implementation decisions.
