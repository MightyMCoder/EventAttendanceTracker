<?php

declare(strict_types=1);

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
