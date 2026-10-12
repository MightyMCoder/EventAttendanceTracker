# EventAttendanceTracker

Admidio 5.0.x plugin for configurable attendance trackers based on individual events.

This branch deliberately uses Admidio 5's legacy plugin layout. Admidio 5 has no plugin manager,
plugin preferences panel, plugin lifecycle or event-series model.

## Installation

1. Copy or clone the repository to `adm_plugins/event-attendance` in an Admidio 5.0.x installation.
2. Open `adm_plugins/event-attendance/index.php` as an administrator and start the installation.
   The plugin creates its database tables, menu entry and per-organization settings itself.

The plugin requires Admidio 5.0.x and PHP 8.2. It supports individual future events only;
Admidio 5 does not provide event-series data that the plugin could use safely.

## Configuration

Open **Plugin-Einstellungen** from the plugin's page. All settings are stored per organization in
the plugin settings table and are managed through the user interface. No `config.php` is required.

## Automatic absent statuses

Set `event_attendance_automation_method` to `cli` or `webcron`. The task snapshots expected
attendees at event start and marks remaining open records as `absent` after the event ends.

- **CLI:** run `php /path/to/admidio/adm_plugins/event-attendance/cli.php --organization=DEMO`
  every 15 minutes. Replace `DEMO` with the organization short name.
- **Webcron:** set a secret `event_attendance_webcron_token` and call
  `adm_plugins/event-attendance/webcron.php?token=…` from an external scheduler.
  Treat the token like a password.

The plugin never installs or modifies a system cronjob itself. Set the method to `disabled` to
prevent both triggers from changing attendance data.

## Updates and removal

Use **Plugin-Einstellungen** to run an available update or to uninstall the plugin. Take a
database backup before updating or uninstalling.

See `PLAN.md` for the functional scope and implementation decisions.
