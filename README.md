# EventAttendanceTracker

Admidio 5.0.x plugin for configurable attendance trackers based on individual events.

This branch deliberately uses Admidio 5's legacy plugin layout. Admidio 5 has no plugin manager,
plugin preferences panel, plugin lifecycle or event-series model.

## Installation

1. Copy or clone the repository to `adm_plugins/event-attendance` in an Admidio 5.0.x installation.
2. Create the plugin tables manually from `db_scripts/install.sql`. Replace every `%PREFIX%` with
   the configured Admidio table prefix (normally `adm`) before running the SQL.
3. Copy `config_sample.php` to `config.php`, then configure the settings required for the
   organization. `config.php` is local configuration and is intentionally ignored by Git.
4. Create a menu entry that points to `adm_plugins/event-attendance/modules/index.php`.
   Restrict that entry to the same roles configured in
   `event_attendance_tracker_manager_roles`. Administrators always have access.

The plugin requires Admidio 5.0.x and PHP 8.2. It supports individual future events only;
Admidio 5 does not provide event-series data that the plugin could use safely.

## Configuration

All settings are returned by `config.php`. Start with `config_sample.php`. Role IDs, not role
names, are used for `event_attendance_tracker_manager_roles`. The configuration is shared by the
Admidio installation; it is not managed through Admidio's preferences page.

## Automatic absent statuses

Set `event_attendance_automation_method` to `cli` or `webcron`. The task snapshots expected
attendees at event start and marks remaining open records as `absent` after the event ends.

- **CLI:** run `php /path/to/admidio/adm_plugins/event-attendance/cli.php` every 15 minutes.
- **Webcron:** set a secret `event_attendance_webcron_token` and call
  `adm_plugins/event-attendance/modules/webcron.php?token=…` from an external scheduler.
  Treat the token like a password.

The plugin never installs or modifies a system cronjob itself. Set the method to `disabled` to
prevent both triggers from changing attendance data.

## Updates and removal

Admidio 5 has no plugin update lifecycle. Before updating this plugin, read the release notes and
run any supplied SQL migration manually with the correct table prefix. Take a database backup
first. To remove the plugin, remove the menu entry and execute `db_scripts/uninstall.sql` after
the same prefix replacement.

See `PLAN.md` for the functional scope and implementation decisions.
