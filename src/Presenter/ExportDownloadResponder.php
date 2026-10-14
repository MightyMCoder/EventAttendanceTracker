<?php
/**
 * Part of the Event Attendance Tracker plugin for Admidio.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 */


namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Utils\FileSystemUtils;
use RuntimeException;

/** Sends a generated report file to the browser and removes its temporary copy. */
final class ExportDownloadResponder
{
    /** @param array{path:string,filename:string,contentType:string} $export */
    public function send(array $export, bool $genericPdfDownload = false): void
    {
        header('Content-Type: ' . $export['contentType']);
        // Mobile browsers reliably download PDF output only without a server-side UTF-8 filename.
        header($genericPdfDownload
            ? 'Content-Disposition: attachment'
            : 'Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private');
        header('Pragma: public');
        header('Content-Length: ' . filesize($export['path']));
        if (ob_get_length() > 0) {
            ob_end_clean();
        }

        try {
            readfile($export['path']);
        } finally {
            try {
                FileSystemUtils::deleteFileIfExists($export['path']);
            } catch (RuntimeException) {
                // Stale temporary exports are removed during Admidio maintenance.
            }
        }
    }
}
