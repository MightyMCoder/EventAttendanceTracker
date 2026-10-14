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
 * PDF document with a recurring title and optional logo header on every page.
 */
final class AttendanceReportPdfDocument extends TCPDF
{
    /**
     * Initialize the TCPDF report document with orientation, header and footer text.
     *
     * Report layouts are rendered through Smarty, while document-wide TCPDF
     * options such as margins, recurring report header and timestamp footer
     * belong to this document.
     */
    public function __construct(
        string $orientation,
        private readonly string $footer,
        private readonly string $logoPath,
        private readonly string $headerTitle
    )
    {
        parent::__construct($orientation, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->setPrintHeader(true);
        $this->setPrintFooter(true);
        $this->SetMargins(10, 34, 10);
        $this->setHeaderMargin(10);
        $this->SetAutoPageBreak(true, 15);
        $this->SetFont('helvetica', '', 8);
    }

    /**
     * Render the configured title and optional logo in every report page header.
     *
     * TCPDF invokes this method while every page is opened. Rendering the logo
     * here avoids writing it at the current cursor position after the report
     * HTML, which would place it only on the final page. The report title uses
     * the same header so continuation pages remain clearly identifiable.
     *
     * @return void
     */
    public function Header(): void
    {
        $margins = $this->getMargins();
        if ($this->logoPath !== '') {
            try {
                $imageSize = getimagesize($this->logoPath);
                $aspectRatio = is_array($imageSize) && $imageSize[1] > 0
                    ? $imageSize[0] / $imageSize[1]
                    : 1.0;
                $maximumWidth = min(50.0, ($this->getPageWidth() - $margins['left'] - $margins['right']) * 0.22);
                $width = min($maximumWidth, 18.0 * $aspectRatio);
                $this->Image($this->logoPath, $margins['left'], 10, $width, $width / $aspectRatio, '', '', '', false, 300);
            } catch (\Throwable) {
                // A malformed image must not prevent the report itself from exporting.
            }
        }

        $this->SetFont('helvetica', 'BU', 16);
        $this->SetXY(0, 15);
        $this->Cell($this->getPageWidth(), 8, $this->headerTitle, 0, 0, 'C');
        $this->SetFont('helvetica', '', 8);
    }

    /**
     * Render the footer on every generated report page.
     *
     * @return void
     */
    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 0, $this->footer, 0, 0, 'C');
    }
}
