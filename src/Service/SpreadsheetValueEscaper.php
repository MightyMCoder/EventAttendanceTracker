<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 */


namespace AdmidioPlugin\EventAttendance\Service;

final class SpreadsheetValueEscaper
{
    public static function escapeFormula(string $value): string
    {
        if ($value !== '' && in_array($value[0], array('=', '+', '-', '@'), true)) {
            return "'" . $value;
        }

        return $value;
    }
}
