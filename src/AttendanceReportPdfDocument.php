<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance;

use Admidio\Infrastructure\PdfDocument;

/**
 * PDF document with an export timestamp on every page.
 */
final class AttendanceReportPdfDocument extends PdfDocument
{
    public function __construct(string $orientation, private readonly string $footer, ?string $hyphenationLanguage = null)
    {
        parent::__construct($orientation, '', $hyphenationLanguage);
    }

    public function defaultPageContent(int $pid = -1): string
    {
        $content = parent::defaultPageContent($pid);
        $page = $this->page->getPage($pid);
        $font = $this->font->insert($this->pon, 'helvetica', '', 8);

        try {
            return $content
                . $this->graph->getStartTransform()
                . $font['out']
                . $this->color->getPdfFillColor('black')
                . $this->getTextCell($this->footer, 10, $page['height'] - 10, $page['width'] - 20, 0, 0, 0, 'T', 'C', self::ZEROCELL)
                . $this->graph->getStopTransform();
        } finally {
            $this->font->popLastFont();
        }
    }
}
