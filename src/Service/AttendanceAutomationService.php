<?php

declare(strict_types=1);

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
        $this->database->startTransaction();

        try {
            // The row lock prevents simultaneous CLI and Webcron invocations from processing the same records.
            $this->database->queryPrepared(
                'SELECT eas_id FROM ' . self::SCHEDULER . ' WHERE eas_name = ? FOR UPDATE',
                array(self::JOB_NAME)
            )->fetch();

            (new AttendanceSnapshotService($this->database))->closeDueEvents(
                new DateTimeImmutable(DATETIME_NOW),
                $organizationId
            );

            $this->database->queryPrepared(
                'UPDATE ' . self::SCHEDULER . ' SET eas_last_run_at = CURRENT_TIMESTAMP WHERE eas_name = ?',
                array(self::JOB_NAME)
            );
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    public function getLastRun(): ?string
    {
        // An interrupted plugin update must not make the settings page unavailable.
        if (!$this->database->tableExists(self::SCHEDULER)) {
            return null;
        }

        $lastRun = $this->database->queryPrepared(
            'SELECT eas_last_run_at FROM ' . self::SCHEDULER . ' WHERE eas_name = ?',
            array(self::JOB_NAME)
        )->fetchColumn();

        return $lastRun === false || $lastRun === null ? null : (string) $lastRun;
    }
}
