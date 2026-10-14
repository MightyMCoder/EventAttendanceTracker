<?php
/**
 * Contains the PDF document implementation used to render attendance reports.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance;

use TCPDF;

/**
 * PDF document with an export timestamp on every page.
 */
final class AttendanceReportPdfDocument extends TCPDF
{
    public function __construct(string $orientation, private readonly string $footer)
    {
        parent::__construct($orientation, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->setPrintHeader(false);
        $this->setPrintFooter(true);
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(true, 15);
        $this->SetFont('helvetica', '', 8);
    }

    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 0, $this->footer, 0, 0, 'C');
    }
}
