# Event Attendance Tracker for Admidio 5

## Scope

This branch provides attendance tracking for individual Admidio 5.0.x events. A tracker assigns roles and one or more future events, captures attendance, supports self-excuses, cancellation, reports and CSV/XLSX/PDF exports.

Admidio 5 has no event-series data model. Trackers on this branch therefore never select or expand recurring events; they can instead include all events of one Admidio calendar. It also has no plugin manager, plugin settings interface, plugin hooks or plugin update lifecycle.

## Legacy integration

- The plugin is installed below `adm_plugins/event-attendance`.
- `autoload.php` loads the plugin namespace without modifying Admidio's Composer configuration.
- Settings are stored per organization in the plugin's own settings table and managed in the UI.
- Administrators always manage trackers. Other managers must be configured by numeric role ID and the same roles must be used for the manually created menu entry.
- There is no v5 settings modal and no automatic menu visibility hook.

## Data lifecycle

`db_scripts/install.sql` and `uninstall.sql` are manual scripts. `%PREFIX%` must be replaced with the installation's table prefix. Future migrations must be released as explicit manual SQL files; they must not rely on Admidio 6 update XML processing.

## Automation

The scheduler stores its most recent execution per organization. It can run via `cli.php` or the token-protected `webcron.php`. No cron entry is installed automatically.

## Validation

Admidio 5 does not provide a test infrastructure. Changes are checked with PHP syntax checks, SQL review and XML/JSON parsing where applicable. Before a release, validate installation and all workflows on an Admidio 5.0.x installation, including menu rights, attendance capture, reports, exports and both automation triggers.
