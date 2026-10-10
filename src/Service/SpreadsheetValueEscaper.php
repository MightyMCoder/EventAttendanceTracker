<?php

declare(strict_types=1);

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
