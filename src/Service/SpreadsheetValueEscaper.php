<?php
/**
 * Contains spreadsheet-cell escaping for Event Attendance Tracker exports.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
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
