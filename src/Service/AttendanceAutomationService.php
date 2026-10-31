<?php
/**
 * Contains automatic processing for completed event-attendance occurrences.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use DateTimeImmutable;

final class AttendanceAutomationService
{
    private const SCHEDULER = TABLE_PREFIX . '_event_attendance_scheduler';
    private const JOB_NAME = 'close-due-events';

    public function __construct(private readonly Database $database)
    {
    }

    public function runForOrganization(int $organizationId): void
    {
        if ($organizationId <= 0) {
            throw new \InvalidArgumentException('Invalid organization ID.');
        }

        $this->database->startTransaction();

        try {
            $this->ensureSchedulerRow($organizationId);
            // The row lock prevents simultaneous CLI and Webcron invocations for this organization.
            $this->database->queryPrepared(
                'SELECT eas_id FROM ' . self::SCHEDULER . ' WHERE eas_org_id = ? AND eas_name = ? FOR UPDATE',
                array($organizationId, self::JOB_NAME)
            )->fetch();

            (new AttendanceSnapshotService($this->database))->closeDueEventsWithinTransaction(
                new DateTimeImmutable(DATETIME_NOW),
                $organizationId
            );

            $this->database->queryPrepared(
                'UPDATE ' . self::SCHEDULER . ' SET eas_last_run_at = CURRENT_TIMESTAMP WHERE eas_org_id = ? AND eas_name = ?',
                array($organizationId, self::JOB_NAME)
            );
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    public function getLastRun(int $organizationId): ?string
    {
        // An interrupted plugin update must not make the settings page unavailable.
        if (!$this->database->tableExists(self::SCHEDULER)) {
            return null;
        }

        $lastRun = $this->database->queryPrepared(
            'SELECT eas_last_run_at FROM ' . self::SCHEDULER . ' WHERE eas_org_id = ? AND eas_name = ?',
            array($organizationId, self::JOB_NAME)
        )->fetchColumn();

        return $lastRun === false || $lastRun === null ? null : (string) $lastRun;
    }

    private function ensureSchedulerRow(int $organizationId): void
    {
        $this->database->queryPrepared(
            'INSERT INTO ' . self::SCHEDULER . ' (eas_org_id, eas_name)
             SELECT ?, ?
              WHERE NOT EXISTS (
                  SELECT 1 FROM ' . self::SCHEDULER . ' WHERE eas_org_id = ? AND eas_name = ?
              )',
            array($organizationId, self::JOB_NAME, $organizationId, self::JOB_NAME)
        );
    }
}
