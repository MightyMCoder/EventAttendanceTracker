<?php

/**
 * Contains the PDF document implementation used to render attendance reports.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance;

use Admidio\Infrastructure\PdfDocument;

/**
 * PDF document with a recurring title, optional logo and export timestamp.
 */
final class AttendanceReportPdfDocument extends PdfDocument
{
    private int $headerLogoId = 0;
    private float $headerLogoAspectRatio = 1.0;

    /**
     * Initialize the report document with its page-independent header and footer.
     */
    public function __construct(
        string $orientation,
        private readonly string $footer,
        private readonly string $headerTitle,
        ?string $hyphenationLanguage = null
    )
    {
        parent::__construct($orientation, '', $hyphenationLanguage);
    }

    /**
     * Add an optional logo to the recurring header.
     *
     * The first page already exists when the native document is initialized.
     * Its logo is therefore appended once here; subsequent pages receive it
     * through defaultPageContent().
     */
    public function setHeaderLogo(string $logoPath): void
    {
        if ($logoPath === '') {
            return;
        }

        try {
            $dimensions = getimagesize($logoPath);
            if (is_array($dimensions) && $dimensions[1] > 0) {
                $this->headerLogoAspectRatio = $dimensions[0] / $dimensions[1];
            }
            $this->headerLogoId = $this->addMarkupImage($logoPath);
            $this->page->addContent($this->getHeaderLogoContent());
        } catch (\Throwable) {
            // A malformed image must not prevent the report from being exported.
            $this->headerLogoId = 0;
        }
    }

    /**
     * Render the recurring report header and footer for a PDF page.
     */
    public function defaultPageContent(int $pid = -1): string
    {
        $page = $this->page->getPage($pid);
        $titleFont = $this->font->insert($this->pon, 'helvetica', 'BU', 16);

        try {
            $content = $this->graph->getStartTransform()
                . $titleFont['out']
                . $this->color->getPdfFillColor('black')
                . $this->getTextCell($this->headerTitle, 10, 10, $page['width'] - 20, 0, 0, 0, 'T', 'C', self::ZEROCELL)
                . $this->graph->getStopTransform()
                . $this->getHeaderLogoContent($pid);
        } finally {
            $this->font->popLastFont();
        }

        $footerFont = $this->font->insert($this->pon, 'helvetica', '', 8);
        try {
            return $content
                . $this->graph->getStartTransform()
                . $footerFont['out']
                . $this->color->getPdfFillColor('black')
                . $this->getTextCell($this->footer, 10, $page['height'] - 10, $page['width'] - 20, 0, 0, 0, 'T', 'C', self::ZEROCELL)
                . $this->graph->getStopTransform();
        } finally {
            $this->font->popLastFont();
        }
    }

    /**
     * Generate the PDF content that positions the configured logo in a header.
     */
    private function getHeaderLogoContent(int $pid = -1): string
    {
        if ($this->headerLogoId === 0) {
            return '';
        }

        try {
            $page = $this->page->getPage($pid);
            $width = min(45.0, ($page['width'] - 20) * 0.22, 15.0 * $this->headerLogoAspectRatio);

            return $this->image->getSetImage($this->headerLogoId, 10, 5, $width, $width / $this->headerLogoAspectRatio, $page['height']);
        } catch (\Throwable) {
            return '';
        }
    }
}
