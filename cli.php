<?php

use Admidio\Infrastructure\Cli\CliApplication;
use Admidio\Infrastructure\Cli\CliTaskRegistry;
use AdmidioPlugin\EventAttendance\Service\AttendanceSnapshotService;

/* This registration file declares metadata only; the callback runs after actor authorization. */
CliTaskRegistry::register(
    'event-attendance:close-due-events',
    'event-attendance',
    static function (array $arguments, array $options): int {
        global $gDb;

        (new AttendanceSnapshotService($gDb))->closeDueEvents(new DateTimeImmutable(DATETIME_NOW));
        CliApplication::writeSuccess('Closed due event-attendance records.', $options);

        return CliApplication::EXIT_SUCCESS;
    },
    'Snapshot expected attendees and mark overdue open attendance records absent.',
    'event-attendance:close-due-events'
);
