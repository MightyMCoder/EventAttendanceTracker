<?php
/**
 * Contains conditional schema migrations for Event Attendance Tracker version 2.0.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\InstallationUpdate;

use Admidio\Infrastructure\Database;

/**
 * Updates legacy and development schemas without intentionally failing SQL statements.
 */
final class UpdateStepsCode
{
    private static Database $db;

    /** Receive the connection used by Admidio's component updater. */
    public static function setDatabase(Database $database): void
    {
        self::$db = $database;
    }

    /** Add calendar source columns and their foreign key only when they are missing. */
    public static function ensureCalendarSource(): void
    {
        $table = TABLE_PREFIX . '_event_attendance_trackers';
        $columns = self::$db->getTableColumns($table);
        if (!in_array('eat_cat_id', $columns, true)) {
            self::$db->queryPrepared('ALTER TABLE ' . $table . ' ADD eat_cat_id integer unsigned NULL');
        }
        if (!in_array('eat_source_start', $columns, true)) {
            self::$db->queryPrepared('ALTER TABLE ' . $table . ' ADD eat_source_start timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        $schema = self::$db->getEngine() === Database::PDO_ENGINE_PGSQL ? 'current_schema()' : 'DATABASE()';
        $constraint = self::$db->queryPrepared(
            'SELECT constraint_name FROM information_schema.table_constraints
              WHERE table_schema = ' . $schema . ' AND table_name = ? AND constraint_name = ?',
            array($table, TABLE_PREFIX . '_fk_eat_calendar')
        )->fetchColumn();
        if ($constraint === false) {
            self::$db->queryPrepared('ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . TABLE_PREFIX
                . '_fk_eat_calendar FOREIGN KEY (eat_cat_id) REFERENCES ' . TBL_CATEGORIES
                . ' (cat_id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        }
        self::$db->initializeTableColumnProperties();
    }

    /** Materialize recurrence references used only by early Admidio 6 development schemas. */
    public static function materializeRecurrenceEvents(): void
    {
        $table = TABLE_PREFIX . '_event_attendance_tracker_events';
        if (!in_array('ete_evr_id', self::$db->getTableColumns($table), true)
            || !in_array('dat_evr_id', self::$db->getTableColumns(TBL_EVENTS), true)) {
            return;
        }
        self::$db->queryPrepared(
            'INSERT INTO ' . $table . ' (ete_tracker_id, ete_dat_id)
             SELECT DISTINCT tracker_events.ete_tracker_id, events.dat_id
               FROM ' . $table . ' AS tracker_events
         INNER JOIN ' . TBL_EVENTS . ' AS events ON events.dat_evr_id = tracker_events.ete_evr_id
              WHERE tracker_events.ete_dat_id IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' AS existing_events
                     WHERE existing_events.ete_tracker_id = tracker_events.ete_tracker_id
                       AND existing_events.ete_dat_id = events.dat_id
                )'
        );
    }

    /** Add missing indexes while retaining the existing indexes of upgraded installations. */
    public static function ensureAttendanceIndexes(): void
    {
        foreach (array(
            array('event_attendance_trackers', 'idx_eat_calendar', 'eat_cat_id', false),
            array('event_attendance_records', 'idx_ear_status', 'ear_status', false),
            array('event_attendance_locks', 'idx_eal_tracker_event', 'eal_tracker_id, eal_event_uuid', true),
            array('event_attendance_locks', 'idx_eal_expires_at', 'eal_expires_at', false)
        ) as list($tableSuffix, $indexSuffix, $columns, $unique)) {
            $table = TABLE_PREFIX . '_' . $tableSuffix;
            $index = TABLE_PREFIX . '_' . $indexSuffix;
            if (self::$db->getEngine() === Database::PDO_ENGINE_PGSQL) {
                $exists = self::$db->queryPrepared(
                    'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
                    array($table, $index)
                )->fetchColumn();
            } else {
                $exists = self::$db->queryPrepared(
                    'SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                    array($table, $index)
                )->fetchColumn();
            }
            if ($exists === false) {
                self::$db->queryPrepared('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $index . ' ON ' . $table . ' (' . $columns . ')');
            }
        }
    }
}
